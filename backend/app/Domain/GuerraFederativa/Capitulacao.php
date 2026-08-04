<?php

namespace App\Domain\GuerraFederativa;

use App\Domain\News\PublicarNoticia;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\Federation;
use App\Models\FederationLedger;
use App\Models\FederationWarProposal;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\WarSetting;
use App\Models\ZoneEvent;
use Illuminate\Support\Facades\DB;

/**
 * A capitulação (A2.10, decisão 9 — GDD §9).
 *
 * *"Sempre disponível, e sempre mais barata do que perder. Quem não pode capitular fica preso numa
 * derrota que já aconteceu, e isso não é dificuldade — é tempo perdido."*
 *
 * ## ⚠️ Quem se rende propõe sem saber o preço — e é isso que a decisão 9 diz
 *
 * *"O vencedor escolhe: zona ou Fert$."* Abrir a capitulação **é** consentir que o outro escolha, e
 * por isso a escolha do preço e o aceite são o mesmo ato: não há um terceiro passo em que o
 * derrotado aprova o que lhe cobraram. O que protege quem se rende não é poder recusar — é o preço
 * ser **estruturalmente limitado**: exatamente uma zona, ou exatamente o valor publicado em
 * `war_settings`. O vencedor escolhe qual, nunca quanto.
 *
 * ## ⚠️ E o vencedor NÃO pode recusar
 *
 * Não existe `recusar()` aqui, ao contrário do `TratadoDePaz`. Recusar uma rendição prenderia o
 * adversário na derrota que o §9 existe para encurtar. O que o vencedor pode fazer é **não
 * responder** — e aí vale o prazo dos 7 dias, que é o relógio que já existia.
 *
 * ## O espólio vai para a FEDERAÇÃO
 *
 * O §11 é explícito: *"o espólio é territorial e vai para a FEDERAÇÃO, não para quem deu o último
 * golpe"*. O Fert$ cai no fundo do vencedor. A zona não tem como pertencer a uma federação — o
 * `owner_colony_id` é de colônia —, então ela vai para a colônia de quem assinou, que é o
 * representante da federação no ato.
 */
class Capitulacao
{
    public function __construct(
        private readonly PropostasDeGuerra $propostas,
        private readonly PublicarNoticia $noticias,
    ) {}

    /** Quem quer sair da guerra abre a capitulação. Sem preço: quem o define é o outro. */
    public function propor(Colony $autor, int $warId): FederationWarProposal
    {
        return DB::transaction(function () use ($autor, $warId) {
            [$guerra, $minha, $outra] = $this->propostas->beligerante($autor, $warId);

            $proposta = $this->propostas->abrir(
                $guerra,
                FederationWarProposal::CAPITULACAO,
                $autor,
                $minha,
            );

            $this->noticias->publicar(
                "Capitulação oferecida: {$minha->name}",
                "{$minha->name} ofereceu capitulação a {$outra->name}. "
                    .'Cabe a quem venceu escolher o espólio: uma zona ou um valor do fundo.',
                'Ministério das Relações',
            );

            return $proposta;
        });
    }

    /** Quem se rendeu pode voltar atrás enquanto o outro não escolheu o preço. */
    public function retirar(Colony $autor, int $warId): void
    {
        DB::transaction(function () use ($autor, $warId) {
            [, $minha] = $this->propostas->beligerante($autor, $warId);

            $proposta = $this->propostas->pendente($warId, FederationWarProposal::CAPITULACAO);

            if (! $proposta) {
                throw new DomainRuleException('sem_proposta', 'Não há capitulação na mesa.');
            }

            if ((int) $proposta->proponente_federation_id !== $minha->id) {
                throw new DomainRuleException(
                    'proposta_alheia',
                    'Esta capitulação é da outra federação. A você cabe escolher o espólio.',
                );
            }

            $proposta->update([
                'status' => 'retirada',
                'respondida_por_colony_id' => $autor->id,
                'respondida_em' => now(),
            ]);
        });
    }

    /**
     * O vencedor escolhe o espólio, e com isso a guerra acaba.
     *
     * @param  string  $precoTipo  `zona` ou `fert`
     * @param  int|null  $zoneId  obrigatório em `zona`: uma zona de quem se rendeu
     */
    public function aceitar(Colony $autor, int $warId, string $precoTipo, ?int $zoneId = null): void
    {
        DB::transaction(function () use ($autor, $warId, $precoTipo, $zoneId) {
            [$guerra, $minha, $outra] = $this->propostas->beligerante($autor, $warId);

            $proposta = $this->propostas->paraResponder(
                $warId,
                FederationWarProposal::CAPITULACAO,
                $minha,
            );

            $cobrado = match ($precoTipo) {
                'zona' => $this->tomarZona($autor, $outra, $zoneId),
                'fert' => $this->tomarFert($minha, $outra, $guerra->id),
                default => throw new DomainRuleException(
                    'preco_invalido',
                    'O espólio é uma zona ou um valor em Fert$.',
                ),
            };

            $proposta->update([
                'status' => 'aceita',
                'respondida_por_colony_id' => $autor->id,
                'respondida_em' => now(),
                'preco_tipo' => $precoTipo,
                'preco_zone_id' => $precoTipo === 'zona' ? $zoneId : null,
                'preco_fert_micro' => $precoTipo === 'fert' ? $cobrado : null,
            ]);

            /*
             * O ranking federativo (D-207). Quem aceita a rendição venceu; quem a ofereceu perdeu —
             * e o resultado vai sempre do ponto de vista do **declarante** da guerra, que pode ser
             * qualquer um dos dois. Passar "1" cru daria a vitória a quem declarou, mesmo quando foi
             * ele quem se rendeu.
             */
            $this->propostas->encerrar(
                $guerra,
                'capitulada',
                (int) $guerra->declarante_id === $minha->id ? 1.0 : 0.0,
            );

            $this->noticias->publicar(
                "Capitulação aceita: {$outra->name} rende-se a {$minha->name}",
                $precoTipo === 'zona'
                    ? "A guerra acabou. {$outra->name} cedeu uma zona a {$minha->name}."
                    : 'A guerra acabou. '.number_format($cobrado / 1_000_000, 2, ',', '.')
                        ." Fert\$ saíram do fundo de {$outra->name}.",
                'Ministério das Relações',
            );
        });
    }

    /**
     * A zona escolhida muda de dono.
     *
     * ⚠️ **A guarnição volta para casa, e não morre.** Na conquista (`ResolverCombates`) os robôs do
     * derrotado são destruídos com a zona, porque não têm para onde recuar — houve batalha. Aqui não
     * houve: é entrega negociada, e apagar as unidades seria cobrar um segundo preço que ninguém
     * combinou, por cima do único que a decisão 9 autoriza.
     *
     * @return int sempre 0 — o "cobrado" em Fert$ desta modalidade
     */
    private function tomarZona(Colony $vencedor, Federation $perdedora, ?int $zoneId): int
    {
        if ($zoneId === null) {
            throw new DomainRuleException('zona_obrigatoria', 'Escolha qual zona quer receber.');
        }

        $zona = NeutralZone::whereKey($zoneId)->lockForUpdate()->first();

        if (! $zona || $zona->owner_colony_id === null) {
            throw new DomainRuleException('zona_invalida', 'Esta zona não existe ou não tem dono.');
        }

        /*
         * ⚠️ A zona tem de ser de quem se rendeu — relido do banco, não do que a tela mandou. Sem
         * esta conferência o vencedor escreveria o id de uma zona de terceiro e a levaria embora,
         * sem guerra nenhuma contra ele.
         */
        $donoFederacao = Colony::whereKey($zona->owner_colony_id)->value('federation_id');

        if ((int) $donoFederacao !== $perdedora->id) {
            throw new DomainRuleException(
                'zona_de_terceiro',
                'Só se pode exigir uma zona da federação que se rendeu.',
            );
        }

        /*
         * Zona sob combate não se entrega: dois donos disputariam o mesmo território no mesmo
         * instante, e o resolvedor de combates leria um dono que mudou debaixo dele. Quem quer
         * receber esta zona espera a batalha terminar — ou escolhe outra.
         */
        $emCombate = Combat::where('zone_id', $zona->id)
            ->whereIn('status', ['marchando', 'em_curso'])
            ->exists();

        if ($emCombate || $zona->cercada()) {
            throw new DomainRuleException(
                'zona_em_combate',
                'Esta zona está sob ataque. Escolha outra, ou espere a batalha acabar.',
            );
        }

        $anterior = (int) $zona->owner_colony_id;

        /* Os robôs voltam para a colônia de quem os tinha. Ver o docblock. */
        Unit::where('zone_id', $zona->id)->update([
            'colony_id' => $anterior,
            'zone_id' => null,
            'status' => 'casa',
            'combat_id' => null,
            'arrives_at' => null,
        ]);

        /* O mesmo relógio de uma zona conquistada (§27.9): o novo dono espera a ocupação. */
        $produtiva = now()->addHours(NeutralZone::OCUPACAO_HORAS);

        $zona->update([
            'owner_colony_id' => $vencedor->id,
            'status' => 'ocupada',
            'occupied_at' => now(),
            'protected_until' => null,
            'productive_at' => $produtiva,
            'last_extraction_at' => $produtiva,
            'sieged_at' => null,
            'maintenance_next_due_at' => $produtiva->copy()->addHours(24),
            'maintenance_unpaid_since' => null,
        ]);

        /*
         * ⚠️ `cedida`, e não `conquistada`. O ranking conta zonas **conquistadas** (§27.13), e uma
         * zona entregue à mesa não foi conquistada — chamá-la assim deixaria comprar posição no
         * ranking com uma guerra encenada entre duas federações amigas. O `RankingDeGuerras` lê
         * `cedida` para reconstruir a POSSE (o tempo de controle muda de mãos de verdade), e não
         * para a contagem de conquistas.
         */
        ZoneEvent::create([
            'zone_id' => $zona->id,
            'type' => 'cedida',
            'colony_id' => $vencedor->id,
            'meta' => ['dono_anterior_colony_id' => $anterior, 'motivo' => 'capitulacao'],
            'created_at' => now(),
        ]);

        return 0;
    }

    /**
     * O valor sai do fundo de quem se rendeu e cai no do vencedor.
     *
     * ⚠️ **Leva-se o que houver, e não se cria dívida.** Se o fundo tem menos que o parâmetro, o
     * vencedor recebe menos e a guerra acaba do mesmo jeito. Bloquear a capitulação por pobreza
     * contradiria o §9 — quem não pode capitular fica preso na derrota — e é a mesma disciplina do
     * §6.6: degrada, não se perde, e nunca fica negativo.
     *
     * @return int o que de facto saiu, em micro-Fert$
     */
    private function tomarFert(Federation $vencedora, Federation $perdedora, int $warId): int
    {
        $preco = (int) WarSetting::singleton()->capitulacao_fert_micro;

        $cobrado = max(0, min($preco, (int) $perdedora->fert_micro));

        if ($cobrado > 0) {
            $perdedora->decrement('fert_micro', $cobrado);
            $vencedora->increment('fert_micro', $cobrado);
        }

        /*
         * O extrato dos dois lados, com o sinal dando a direção (D-164). Duas linhas, e não uma:
         * o `federation_ledger` é por federação, e um lançamento só deixaria metade da transferência
         * sem registro no extrato de quem a sofreu — que é justamente quem vai querer conferir.
         */
        foreach ([[$perdedora->id, -$cobrado], [$vencedora->id, $cobrado]] as [$federacaoId, $valor]) {
            FederationLedger::create([
                'federation_id' => $federacaoId,
                'colony_id' => null,
                'type' => 'capitulacao',
                'amount' => $valor,
                'resource_type' => null,
                'ref' => "guerra:{$warId}:capitulacao",
                'created_at' => now(),
            ]);
        }

        return $cobrado;
    }
}
