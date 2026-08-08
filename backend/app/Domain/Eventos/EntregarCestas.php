<?php

namespace App\Domain\Eventos;

use App\Models\Colony;
use App\Models\GameEvent;
use App\Models\GameEventEntrega;
use App\Models\Ledger;
use App\Models\ResourceType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A cesta do evento chegando às colônias (D-232).
 *
 * ## O que é uma cesta, e por que ela não é um subsídio
 *
 * O Tesouro já sabe distribuir (D-113), e é o caminho certo para o governo repartir o que **arrecadou**
 * — o tributo do §2.1 saindo de onde entrou. Uma cesta de evento é outra coisa: o Governo **emite**.
 * Nada foi arrecadado, nenhum saldo de ninguém baixou, e forçar isso pelo Tesouro exigiria creditar
 * o caixa do nada primeiro, deixando um saldo inflado de governo depois que o evento acabasse.
 *
 * Emissão sem contrapartida já existe no jogo e tem forma declarada: o salário do conciliador
 * (§26.7), a recompensa de missão (§06) e o `ajuste_admin` (D-61). Todas escrevem no ledger com tipo
 * próprio, e é o que esta classe faz — `presente_evento`, uma linha por recurso, por colônia.
 *
 * ## ⚠️ Idempotente pela chave única, não pela conferência
 *
 * O laço confere `entregues` antes de entregar, mas **não é a conferência que garante** — é o
 * `unique(game_event_id, colony_id)`. Duas execuções concorrentes (o scheduler e uma mão no painel
 * ao mesmo tempo) passariam as duas pela conferência; só uma sobrevive ao INSERT.
 *
 * A ordem importa e é deliberada: **a entrega grava PRIMEIRO** e credita depois, tudo na mesma
 * transação. Se o INSERT colidir, a transação inteira volta e nada foi creditado. O contrário —
 * creditar e depois marcar — daria recursos a quem já tinha recebido, e o ledger é append-only:
 * não haveria como desfazer.
 *
 * ## Alcança quem chegou depois
 *
 * A varredura é por **colônia sem linha de entrega**, não por "colônias que existiam quando o evento
 * começou". É o que a decisão do usuário pede: quem fundar no dia 12 de uma janela de 30 recebe a
 * cesta na primeira passagem seguinte. Fora da janela, ninguém recebe mais nada — `vigenteEm()`
 * responde por isso, e cancelar o evento fecha a torneira sem tirar de quem já pegou.
 */
class EntregarCestas
{
    /** A chave sentinela de Fert$ dentro de `recompensas` — a mesma do Tesouro, para não inventar duas. */
    public const FERT = '__fert__';

    /**
     * Entrega as cestas de todos os eventos vigentes que têm uma.
     *
     * @return array<string,int> slug => quantas colônias receberam agora
     */
    public function todos(): array
    {
        $agora = now();

        $eventos = GameEvent::whereIn('status', ['ativo', 'cancelado'])
            ->where('comeca_em', '<=', $agora)
            ->where('termina_em', '>=', $agora)
            ->whereNotNull('recompensas')
            ->orderBy('id')
            ->get()
            ->filter(fn (GameEvent $e) => $e->vigenteEm($agora) && $e->temCesta());

        $feito = [];

        foreach ($eventos as $evento) {
            $n = $this->doEvento($evento);

            if ($n > 0) {
                $feito[$evento->slug] = $n;
            }
        }

        return $feito;
    }

    /**
     * Entrega a cesta de UM evento a quem ainda não recebeu.
     *
     * ⚠️ Não confere `vigenteEm()` — quem chama decide. `todos()` só traz os vigentes; o painel
     * entrega sob demanda e precisa poder alcançar um evento que acabou de ser ativado no mesmo
     * segundo, antes de o scheduler passar.
     *
     * @return int quantas colônias receberam nesta passagem
     */
    public function doEvento(GameEvent $evento): int
    {
        $cesta = $this->cestaValida($evento);

        if ($cesta === []) {
            return 0;
        }

        $jaReceberam = GameEventEntrega::where('game_event_id', $evento->id)
            ->pluck('colony_id');

        /*
         * O escopo do evento vale aqui como vale no modificador: `colonia` entrega a uma só. É o
         * dry-run em escala de um que a §Segurança da A2.8 pede — testar a cesta numa colônia antes
         * de soltá-la no mundo.
         */
        $destinos = Colony::query()
            ->when($evento->escopo === 'colonia', fn ($q) => $q->whereKey($evento->colony_id))
            ->whereNotIn('id', $jaReceberam)
            ->orderBy('id')
            ->get();

        $n = 0;

        foreach ($destinos as $colonia) {
            if ($this->entregar($evento, $colonia, $cesta)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Uma colônia, uma cesta, uma transação.
     *
     * @param  array<string,int>  $cesta
     * @return bool false se outra execução chegou primeiro — e então nada foi creditado
     */
    private function entregar(GameEvent $evento, Colony $colonia, array $cesta): bool
    {
        try {
            DB::transaction(function () use ($evento, $colonia, $cesta) {
                // PRIMEIRO a marca. Se ela colidir, o crédito abaixo nem chega a acontecer.
                GameEventEntrega::create([
                    'game_event_id' => $evento->id,
                    'colony_id' => $colonia->id,
                    'entregue_em' => now(),
                    'cesta' => $cesta,
                ]);

                $ref = "evento:{$evento->slug}";

                foreach ($cesta as $recurso => $qtd) {
                    if ($recurso === self::FERT) {
                        DB::table('colonies')->where('id', $colonia->id)->increment('fert_micro', $qtd);

                        Ledger::create([
                            'colony_id' => $colonia->id, 'type' => 'presente_evento',
                            'amount' => $qtd, 'resource_type' => null,
                            'ref' => $ref, 'created_at' => now(),
                        ]);

                        continue;
                    }

                    /*
                     * ⚠️ `firstOrCreate` antes do `increment`, e não o `increment` sozinho.
                     *
                     * O `CreateColony` abre uma linha por recurso do catálogo na fundação — mas o
                     * catálogo cresce, e uma colônia fundada antes de um recurso novo não tem linha
                     * para ele. Um `increment` sobre zero linhas **não falha**: soma em nada, em
                     * silêncio. O presente sumiria e o ledger juraria que foi entregue.
                     *
                     * `storage_cap` nasce nulo de propósito: é o piso PESSOAL do D-217, e quem não
                     * tinha estoque nenhum não tem piso a preservar.
                     */
                    $linha = $colonia->resources()->firstOrCreate(
                        ['resource_type' => $recurso],
                        ['amount' => 0, 'storage_cap' => null],
                    );

                    $linha->increment('amount', $qtd);

                    Ledger::create([
                        'colony_id' => $colonia->id, 'type' => 'presente_evento',
                        'amount' => $qtd, 'resource_type' => $recurso,
                        'ref' => $ref, 'created_at' => now(),
                    ]);
                }
            });
        } catch (QueryException $e) {
            /*
             * Violação da chave única: outra execução entregou a esta colônia entre a conferência e
             * o INSERT. É o caso normal da corrida, não um defeito — a transação voltou inteira e
             * ninguém recebeu duas vezes. Qualquer outro erro de banco continua subindo.
             */
            if (! $this->ehChaveDuplicada($e)) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * A cesta filtrada pelo catálogo: só recursos que existem, só quantidades positivas.
     *
     * O evento é editável pelo painel e pelo `artisan`; um code errado ficaria guardado no JSON sem
     * ninguém notar, e a alternativa a filtrar aqui seria uma `QueryException` no meio de uma
     * entrega em massa, com metade das colônias já servidas.
     *
     * @return array<string,int>
     */
    public function cestaValida(GameEvent $evento): array
    {
        $codigos = ResourceType::pluck('code')->push(self::FERT)->all();

        $cesta = [];

        foreach ((array) ($evento->recompensas ?? []) as $recurso => $qtd) {
            if (in_array($recurso, $codigos, true) && (int) $qtd > 0) {
                $cesta[$recurso] = (int) $qtd;
            }
        }

        return $cesta;
    }

    private function ehChaveDuplicada(QueryException $e): bool
    {
        // 23000/23505 é a família de violações de integridade — a mesma no MariaDB e no SQLite.
        return str_starts_with((string) $e->getCode(), '23');
    }
}
