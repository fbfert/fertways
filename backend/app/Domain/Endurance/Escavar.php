<?php

namespace App\Domain\Endurance;

use App\Domain\Eventos\EntregarCestas;
use App\Domain\Marco\Curva;
use App\Domain\Telemetria\RegistrarEvento;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\EnduranceEscavacao;
use App\Models\EnduranceEscavacaoSetting;
use App\Models\EnduranceItem;
use App\Models\Ledger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Escavar uma seção da Endurance (A2.9 / GDD_ALPHA2 §11; D-249).
 *
 * O §11 dá à Endurance o papel de *"área de escavação/desmontagem controlada"* e *"origem de peças e
 * artefatos"*, e o D-187 registrou que o item único nascia na compra porque escavar não existia.
 *
 * ## ⚠️ A sorte não tem número próprio: o peso é o estoque
 *
 * Cada unidade ainda enterrada numa seção é um bilhete. Um comum com 40 unidades tem 40 bilhetes; o
 * único tem 1. A escassez que o operador já escreve em `quantidade_total` **é** a probabilidade — e
 * por isso esta mecânica não precisou de uma tabela de chances que ninguém publicou. Conforme a seção
 * é escavada, o que sobra fica mais raro, que é exatamente o que uma escavação de verdade faz.
 *
 * ## A peça é sorteada e RESERVADA no início
 *
 * Sortear no fim deixaria duas escavações simultâneas disputarem o mesmo único, e a perdedora
 * terminaria de mãos vazias depois de pagar. Reservar no início (`quantidade_vendida` sobe na mesma
 * transação, sob `lockForUpdate`) garante que toda escavação aceita acha alguma coisa — e a recusa,
 * quando a seção está vazia, acontece **antes** de cobrar.
 *
 * A colônia só vê o que achou quando a escavação termina (`ConcluirEscavacoes`).
 */
class Escavar
{
    public function __construct(private Tesouro $tesouro) {}

    public function handle(Colony $colony, string $secao): EnduranceEscavacao
    {
        $config = EnduranceEscavacaoSetting::singleton();

        if (! $config->ligada()) {
            throw new DomainRuleException('escavacao_desligada', 'A escavação da Endurance ainda não foi aberta.');
        }

        if (! array_key_exists($secao, EnduranceItem::SECOES)) {
            throw new DomainRuleException('secao_desconhecida', "Seção inexistente: {$secao}");
        }

        return DB::transaction(function () use ($colony, $secao, $config) {
            $colonia = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            // Uma de cada vez: a equipe de escavação de uma colônia está num lugar só.
            if (EnduranceEscavacao::where('colony_id', $colonia->id)
                ->where('status', EnduranceEscavacao::ESCAVANDO)->exists()) {
                throw new DomainRuleException('ja_escavando', 'Sua equipe já está escavando. Espere ela voltar.');
            }

            $item = $this->sortear($this->achados($colonia, $secao));

            if ($item === null) {
                throw new DomainRuleException(
                    'secao_esgotada',
                    'Não há mais nada para achar em '.EnduranceItem::SECOES[$secao].' — por enquanto.',
                );
            }

            $custo = $config->custo ?? [];
            $this->cobrar($colonia, $custo, $secao);

            $item->increment('quantidade_vendida');

            $agora = now();

            /*
             * `endurance_interacao` estava declarado na telemetria desde a A2.0 e nunca tinha tido
             * emissor. A escavação é a primeira interação com a Endurance que não é compra — e a
             * compra o ledger já vê. `adiar`: estamos dentro da transação (D-173).
             */
            app(RegistrarEvento::class)->handle(
                'endurance_interacao', $colonia->user, $colonia,
                ['acao' => 'escavacao_iniciada', 'secao' => $secao],
                adiar: true,
            );

            return EnduranceEscavacao::create([
                'colony_id' => $colonia->id,
                'secao' => $secao,
                'endurance_item_id' => $item->id,
                'status' => EnduranceEscavacao::ESCAVANDO,
                'custo_pago' => $custo ?: null,
                'starts_at' => $agora,
                'finishes_at' => $agora->copy()->addMinutes((int) $config->duracao_minutos),
            ]);
        });
    }

    /**
     * O que ainda está enterrado nesta seção e que ESTA colônia pode achar.
     *
     * Peça acima do marco da colônia não entra no sorteio: achar uma peça que a loja recusaria
     * vender seria contornar o portão do marco pela porta dos fundos.
     *
     * @return Collection<int,EnduranceItem>
     */
    public function achados(Colony $colonia, string $secao): Collection
    {
        $marco = Curva::marco((int) $colonia->xp);

        return EnduranceItem::where('secao', $secao)
            ->where('origem', EnduranceItem::ESCAVACAO)
            ->whereColumn('quantidade_vendida', '<', 'quantidade_total')
            ->where(fn ($q) => $q->whereNull('marco_minimo')->orWhere('marco_minimo', '<=', $marco))
            ->liberadas(now())
            ->lockForUpdate()
            ->get();
    }

    /** Um bilhete por unidade enterrada. */
    private function sortear(Collection $achados): ?EnduranceItem
    {
        $bilhetes = $achados->sum(fn (EnduranceItem $i) => $i->estoqueLivre());

        if ($bilhetes <= 0) {
            return null;
        }

        $sorteado = random_int(1, $bilhetes);

        foreach ($achados as $item) {
            $sorteado -= $item->estoqueLivre();

            if ($sorteado <= 0) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<string,int> $custo recurso => quantidade; Fert$ em micro na chave `__fert__` */
    private function cobrar(Colony $colonia, array $custo, string $secao): void
    {
        $fert = (int) ($custo[EntregarCestas::FERT] ?? 0);
        $recursos = array_filter(
            array_diff_key($custo, [EntregarCestas::FERT => 0]),
            fn ($q) => (int) $q > 0,
        );

        if ($fert > 0 && $colonia->fert_micro < $fert) {
            throw new DomainRuleException(
                'fert_insuficiente',
                'Faltam '.number_format(($fert - $colonia->fert_micro) / Colony::MICRO_POR_FERT, 2, ',', '.').' Fert$.',
            );
        }

        $estoque = $colonia->resources()->lockForUpdate()->get()->keyBy('resource_type');

        // Tudo conferido antes de qualquer débito: falta nomeada, um recurso por vez não.
        $faltas = [];
        foreach ($recursos as $recurso => $qtd) {
            $tem = (int) ($estoque->get($recurso)?->amount ?? 0);

            if ($tem < (int) $qtd) {
                $faltas[] = "{$recurso} ({$tem} de {$qtd})";
            }
        }

        if ($faltas !== []) {
            throw new DomainRuleException('recurso_insuficiente', 'Falta para escavar: '.implode(', ', $faltas).'.');
        }

        $ref = "escavacao:{$secao}";
        $agora = now();

        if ($fert > 0) {
            $colonia->decrement('fert_micro', $fert);
            $this->tesouro->creditarFert($fert, "escavacao_endurance:{$colonia->id}");

            Ledger::create([
                'colony_id' => $colonia->id, 'type' => 'escavacao_endurance', 'amount' => -$fert,
                'resource_type' => null, 'ref' => $ref, 'created_at' => $agora,
            ]);
        }

        foreach ($recursos as $recurso => $qtd) {
            $estoque[$recurso]->decrement('amount', (int) $qtd);

            Ledger::create([
                'colony_id' => $colonia->id, 'type' => 'escavacao_endurance', 'amount' => -(int) $qtd,
                'resource_type' => $recurso, 'ref' => $ref, 'created_at' => $agora,
            ]);
        }
    }
}
