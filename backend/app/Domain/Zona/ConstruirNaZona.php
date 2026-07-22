<?php

namespace App\Domain\Zona;

use App\Domain\Building\BuildingSpecs;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\FilaSetting;
use App\Models\NeutralZone;
use Illuminate\Support\Facades\DB;

/**
 * Ergue e evolui as estruturas da zona neutra (GDD §17.4; docs/decisoes.md D-67).
 *
 * **O material vem do CANTEIRO, não do estoque da colônia** — e essa é a decisão que dá forma a tudo
 * aqui. As obras da zona exigem **entrega física**: despacha-se um veículo com Metal Bruto e Ligas, o
 * material entra no canteiro (`zone_materials`), e só então a obra pode começar.
 *
 * ⚠️ Isso **contradiz a ocupação**, que debita direto da colônia e ergue o Posto de Comando sem
 * veículo nenhum (D-52). É deliberado: a ocupação é o ato de **chegar**, e as obras são o ato de
 * **investir**. Não "conserte" um pelo outro sem perguntar.
 *
 * E há uma consequência que ninguém planejou e que é boa: **o cerco impede fortificar sob sítio**.
 * Nada entra nem sai de uma zona cercada (D-66), então o material não chega, então a obra não começa.
 * Quem cerca impede o cercado de se defender melhor.
 */
class ConstruirNaZona
{
    public function __construct(private readonly BuildingSpecs $specs) {}

    /**
     * @param  int  $slot  o buraco da colmeia (`ZonaSlots`) onde erguer, ou o slot já ocupado pela
     *                      estrutura que se quer evoluir. Escolhido pelo colono — mesma UX do
     *                      `ConstruirEmSlot` da colônia (D-59).
     */
    public function handle(Colony $colony, NeutralZone $zona, string $estrutura, int $slot): void
    {
        if (! in_array($estrutura, Estruturas::CONSTRUIVEIS, true)) {
            throw new DomainRuleException(
                'estrutura_invalida',
                "A zona não tem {$estrutura}. O Posto de Comando nasce com a ocupação e não se ergue.",
            );
        }

        DB::transaction(function () use ($colony, $zona, $estrutura, $slot) {
            $zona = NeutralZone::whereKey($zona->id)->lockForUpdate()->firstOrFail();

            if ($zona->owner_colony_id !== $colony->id) {
                throw new DomainRuleException('zona_nao_e_sua', 'Esta zona neutra não é sua.');
            }

            ZonaSlots::exigirEscolhivel($slot, $zona->level);

            $existente = $zona->zoneStructures()->where('slot', $slot)->first();

            if ($existente !== null && $existente->type !== $estrutura) {
                throw new DomainRuleException(
                    'slot_ocupado',
                    'Este slot já tem '.Estruturas::de($existente->type)['nome'].'.',
                );
            }

            if ($existente === null
                && ! in_array($estrutura, Estruturas::REPETIVEIS, true)
                && $zona->zoneStructures()->where('type', $estrutura)->exists()
            ) {
                throw new DomainRuleException(
                    'estrutura_unica',
                    Estruturas::de($estrutura)['nome'].' só pode existir uma vez na zona.',
                );
            }

            /*
             * O slot pode estar vazio em `zone_structures` e AINDA ASSIM já ter obra em curso — o
             * teto de obras simultâneas (`FilaSetting::zona_vagas`, abaixo) permite mais de uma ao
             * mesmo tempo, e a estrutura só vira linha quando a obra CONCLUI (`ConcluirObrasDaZona`).
             * Sem esta trava, duas obras concorrentes no mesmo slot vazio - digamos, Muralha e
             * Abrigo - nasceriam sem conflito e a segunda a terminar sobrescreveria o tipo da
             * primeira quando `ConcluirObrasDaZona` gravasse a linha.
             */
            if ($zona->obras()->where('slot', $slot)->exists()) {
                throw new DomainRuleException(
                    'slot_em_obra',
                    'Este slot já tem uma obra em curso. Espere-a terminar.',
                );
            }

            /*
             * Cercada, nada entra nem sai (§28.10, D-66) — e isso alcança a obra. O material já no
             * canteiro poderia, em tese, ser usado; mas erguer uma muralha debaixo do cerco é
             * exatamente o que o sítio existe para impedir. Sem obra sob sítio.
             */
            if ($zona->cercada()) {
                throw new DomainRuleException(
                    'zona_cercada',
                    'A zona está cercada: não se constrói sob sítio. Rompa o cerco ou espere as 48 h.',
                );
            }

            /*
             * O teto de obras simultâneas na zona (D-111) — do operador, não mais 1 cravado no
             * código. Diferente da fila da colônia, aqui não há "queued esperando o antecessor":
             * cada obra só nasce quando o canteiro já tem o material, então até o teto, todas as
             * que tiverem material começam na hora, cada uma com o seu relógio.
             */
            $vagas = FilaSetting::singleton()->zona_vagas;
            $emCurso = $zona->obras()->count();

            if ($emCurso >= $vagas) {
                throw new DomainRuleException(
                    'obra_em_curso',
                    $vagas === 1
                        ? 'Já há uma obra em curso nesta zona. Espere-a terminar.'
                        : "A zona já tem {$vagas} obras em curso ao mesmo tempo. Espere uma terminar.",
                );
            }

            $atual = $existente?->level ?? 0;
            $alvo = $atual + 1;
            $max = $this->specs->nivelMaximo($estrutura);

            if ($alvo > $max) {
                throw new DomainRuleException(
                    'nivel_maximo',
                    Estruturas::de($estrutura)['nome']." já está no nível máximo ({$atual}).",
                );
            }

            /*
             * Até 2026-07-19 (D-122) esta classe lia `building_specs` direto — um ajuste do admin
             * em `building_specs_overrides` (D-107/D-108) não tinha efeito nenhum nas estruturas
             * de zona. `BuildingSpecs::para()` é o MESMO caminho que a colônia já usa (custo/tempo
             * com o override por cima do GDD), e também é quem barra tempo indefinido — foi essa
             * falta que deixou o Depósito de Zona Neutra construir instantâneo por um bom tempo
             * (`build_time_seconds` NULL nunca era conferido aqui).
             */
            $spec = $this->specs->para($estrutura, $alvo);

            $this->debitarDoCanteiro($zona, $spec['custo'], $estrutura, $alvo);

            $zona->obras()->create([
                'structure' => $estrutura,
                'slot' => $slot,
                'target_level' => $alvo,
                'finishes_at' => now()->addSeconds($spec['tempo_segundos']),
            ]);
        });
    }

    /**
     * O canteiro paga a obra. Se faltar material, a obra **não começa** — e a mensagem diz o que
     * falta e quanto, porque o colono tem de despachar um veículo com exatamente isso.
     *
     * @param  array<string,int>  $custo
     */
    private function debitarDoCanteiro(NeutralZone $zona, array $custo, string $estrutura, int $alvo): void
    {
        $canteiro = $zona->materiais()->lockForUpdate()->get()->keyBy('resource_type');

        $faltam = [];

        foreach ($custo as $recurso => $qtd) {
            $tem = (int) ($canteiro->get($recurso)?->amount ?? 0);

            if ($tem < $qtd) {
                $faltam[] = "{$recurso}: faltam ".($qtd - $tem);
            }
        }

        if ($faltam !== []) {
            $nome = Estruturas::de($estrutura)['nome'];

            throw new DomainRuleException(
                'canteiro_insuficiente',
                "O canteiro da zona não tem material para {$nome} nível {$alvo}. "
                .implode(' · ', $faltam)
                .'. Despache um veículo com o material até a zona.',
            );
        }

        foreach ($custo as $recurso => $qtd) {
            $canteiro[$recurso]->decrement('amount', $qtd);
        }
    }
}
