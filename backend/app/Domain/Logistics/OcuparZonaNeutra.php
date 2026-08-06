<?php

namespace App\Domain\Logistics;

use App\Domain\Federacao\Aliancas;
use App\Domain\Marco\ConcederXp;
use App\Domain\Marco\ExigirMarco;
use App\Domain\Missoes\Progresso;
use App\Domain\Populacao\Parametros;
use App\Domain\Zona\Operadores;
use App\Domain\Zona\ZonaSlots;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\FederationSetting;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\ZoneEvent;
use Illuminate\Support\Facades\DB;

/**
 * Ocupa uma zona neutra livre (GDD §07; docs/decisoes.md D-52, Fatia 1).
 *
 * A ocupação é **pesada** (arbitrada no D-52): a colônia ergue um Posto de Comando (custo próprio),
 * guarnece a zona com 20 Robôs Mineradores (custo publicado no §4.3) e espera o estabelecimento —
 * o tempo do Posto mais o tempo de ocupação — antes de a extração começar. Tudo numa transação: ou
 * a zona é tomada e paga por inteiro, ou nada acontece.
 *
 * O que é publicado (custo do Robô, defesa, proteção de 8 dias) vem do GDD; o que foi inventado
 * (custo/tempo do Posto, tempo de ocupação) está nas constantes de `NeutralZone`, marcado lá.
 */
class OcuparZonaNeutra
{
    /** 1 Fert$ = 1.000.000 de micro (a mesma escala de `colonies.fert_micro`). */
    private const MICRO = 1_000_000;

    public function handle(Colony $colony, NeutralZone $zona): NeutralZone
    {
        /*
         * O gate do §05, vivo desde o D-75: zonas neutras são do marco 20 (Desbravador). Fica FORA
         * da transação de propósito — barrar não precisa de lock. E é AQUISIÇÃO: quem já tem zona
         * continua com ela (posse preservada); só ocupar OUTRA passa por aqui.
         */
        app(ExigirMarco::class)->exigir($colony, 20, 'Ocupar uma zona neutra');

        return DB::transaction(function () use ($colony, $zona) {
            // Trava a zona e a colônia: duas requisições não podem tomar a mesma zona nem gastar
            // o mesmo recurso. Reconfere a ocupação sob a trava.
            $zona = NeutralZone::whereKey($zona->id)->lockForUpdate()->firstOrFail();

            if ($zona->estaOcupada()) {
                throw new DomainRuleException('zona_ocupada', 'Esta zona neutra já tem dono.');
            }

            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            // Teto de zonas por jogador — arbitrado no D-84. O GDD não publica número nenhum.
            $possuidas = NeutralZone::where('owner_colony_id', $colony->id)->count();
            if ($possuidas >= NeutralZone::TETO_ZONAS_POR_COLONIA) {
                throw new DomainRuleException(
                    'teto_de_zonas',
                    'Você já ocupa o máximo de '.NeutralZone::TETO_ZONAS_POR_COLONIA.' zonas neutras.',
                );
            }

            if ($colony->federation_id !== null) {
                $this->conferirTetoDaFederacao($colony->federation_id);
            }

            /*
             * ⚠️ Impedimento por falta de população (A2.6): ocupar zona nova exige gente livre para
             * operá-la.
             *
             * Só a ocupação NOVA é barrada. O que já existe continua funcionando — o §6.7 promete que
             * nenhuma colônia para por uma regra que não existia quando ela foi construída, e uma
             * zona já ocupada nunca é confiscada por falta de equipe: ela degrada (§6.6).
             */
            $parametrosDePopulacao = app(Parametros::class);
            $this->conferirPopulacao($colony);

            $custo = $this->custoDeRecursos();
            $this->debitarRecursos($colony, $custo, $zona);
            $this->debitarFert($colony, NeutralZone::POSTO_FERT, $zona);

            $agora = now();
            // Estabelecimento: ergue o Posto, depois o tempo de ocupação. Só então extrai.
            $produtiva = $agora->copy()->addHours(NeutralZone::POSTO_HORAS + NeutralZone::OCUPACAO_HORAS);

            $zona->update([
                'owner_colony_id' => $colony->id,
                /*
                 * A equipe vai JUNTO com a ocupação — é a "transferência colônia → zona" da fase,
                 * acontecendo no momento em que ela faz sentido. Sem isto, toda zona nova nasceria
                 * degradada e o jogador teria de lembrar de povoá-la depois de já ter pago por ela.
                 */
                'operadores' => $parametrosDePopulacao->ativo()
                    ? $parametrosDePopulacao->operadoresDeZona((int) $zona->level)
                    : 0,
                'status' => 'protegida',
                'occupied_at' => $agora,
                'protected_until' => $agora->copy()->addDays(NeutralZone::DIAS_DE_PROTECAO),
                'productive_at' => $produtiva,
                'deposit_amount' => 0,
                // A extração é creditada a partir daqui: nada rende durante o estabelecimento.
                'last_extraction_at' => $produtiva,
                // Manutenção territorial (D-84): a primeira cobrança vence 24 h depois de a zona
                // ficar produtiva, não 24 h depois de ocupar — quem ainda está se estabelecendo
                // não deve o primeiro dia de manutenção antes mesmo de extrair qualquer coisa.
                'maintenance_next_due_at' => $produtiva->copy()->addHours(24),
                'maintenance_unpaid_since' => null,
            ]);

            // O Posto de Comando (centro da colmeia) e o Depósito nascem com a ocupação (D-52),
            // como sempre — só que agora como linhas de `zone_structures`, não colunas (D-144). O
            // Depósito pode já existir (zonas livres nascem com ele, `NeutralZoneSeeder`).
            $zona->zoneStructures()->firstOrCreate(
                ['slot' => ZonaSlots::POSTO_SLOT],
                ['type' => 'posto_de_comando', 'level' => 1],
            );
            $zona->zoneStructures()->firstOrCreate(
                ['slot' => ZonaSlots::NIVEL1_SLOTS[0]],
                ['type' => 'deposito_de_zona_neutra', 'level' => 1],
            );

            // Desbravador de fato: ocupar rende XP (D-75) — dentro da transação, com o resto.
            app(ConcederXp::class)->handle($colony->id, 'zona_ocupada', "zona:{$zona->id}");
            app(Progresso::class)->registrar($colony->id, 'zona_ocupada');

            // Histórico da zona (D-86): a primeira linha da vida dela com dono.
            ZoneEvent::create([
                'zone_id' => $zona->id, 'type' => 'ocupada', 'colony_id' => $colony->id,
                'created_at' => $agora,
            ]);

            /*
             * A guarnição são 20 Robôs Mineradores — e desde o D-66 eles são LINHAS, não um
             * contador. O §27.2 os torna defensores improvisados (25% da Sentinela, ataque zero),
             * e o §27.6 exige que cada um tenha o seu HP: quem sobrevive a um ataque volta ferido,
             * quem chega a zero morre de vez. Um `int` não guarda isso.
             */
            $agora2 = now();

            Unit::insert(array_fill(0, NeutralZone::GUARNICAO_INICIAL, [
                'zone_id' => $zona->id,
                'colony_id' => null,
                'type' => 'robo_minerador',
                'level' => 1,
                'hp_bps' => Unit::INTEIRA,
                'status' => 'na_zona',
                'created_at' => $agora2,
                'updated_at' => $agora2,
            ]));

            return $zona->fresh();
        });
    }

    /**
     * O limite antimonopólio do §04 (D-119): uma federação não passa de X% de TODAS as zonas
     * ocupadas do jogo (não só as suas — o denominador é o planeta inteiro). Parâmetro do operador
     * (`FederationSetting`), porque o GDD escreve "20% → 10%" e nunca diz de quê.
     *
     * Checa o estado ANTES desta ocupação — mesmo padrão do teto de 5 zonas por colônia, acima:
     * bloqueia a PRÓXIMA zona quando a federação já está no teto ou acima dele, não a que a levou
     * até lá. Evita o caso degenerado de checar "depois" (a primeíssima zona do jogo inteiro
     * sempre seria 100% de um total de 1, e travaria o próprio nascimento do sistema).
     */
    /**
     * Há colono livre para operar mais uma zona? (A2.6)
     *
     * O princípio da fase é *"poucos humanos operam muitos robôs"*: uma zona nível 1 pede 2 colonos,
     * não uma cidade. O impedimento existe para que expandir território custe **gente**, e não só
     * recurso — sem isso, população seria um número no canto da tela.
     *
     * Com a população desligada, não impede nada.
     */
    private function conferirPopulacao(Colony $colony): void
    {
        $operadores = app(Operadores::class);
        $parametros = app(Parametros::class);

        if (! $parametros->ativo()) {
            return;
        }

        $exigidos = $parametros->operadoresDeZona(1);
        $livres = $operadores->disponivel($colony);

        if ($livres < $exigidos) {
            throw new DomainRuleException(
                'sem_populacao',
                "Uma zona nova precisa de {$exigidos} colono(s) para operar, e você tem "
                    .max(0, $livres).' livre(s). Amplie a habitação ou traga operadores de outra zona.',
            );
        }
    }

    private function conferirTetoDaFederacao(int $federationId): void
    {
        $totalDeZonas = NeutralZone::whereNotNull('owner_colony_id')->count();

        if ($totalDeZonas === 0) {
            return;
        }

        /*
         * ⚠️ O teto conta o BLOCO, não a federação sozinha (A2.5).
         *
         * Uma federação aliada a outras duas não são 12 colônias: são até 36 operando em conjunto.
         * Se este limite continuasse olhando só a federação, aliar-se viraria **lavanderia de
         * monopólio** — a regra do §04 seria contornada montando três federações aliadas em vez de
         * uma grande, que é exatamente o arranjo que ela existe para impedir.
         *
         * Sem aliança nenhuma, `bloco()` devolve só a própria federação e a conta é a de sempre.
         */
        $bloco = app(Aliancas::class)->bloco($federationId);

        $doBloco = NeutralZone::whereNotNull('owner_colony_id')
            ->whereHas('owner', fn ($q) => $q->whereIn('federation_id', $bloco))
            ->count();

        $tetoBps = FederationSetting::singleton()->teto_ocupacao_zonas_bps;

        if (intdiv($doBloco * 10_000, $totalDeZonas) >= $tetoBps) {
            throw new DomainRuleException(
                'teto_antimonopolio_da_federacao',
                (count($bloco) > 1 ? 'A sua federação e as aliadas dela já ocupam ' : 'A sua federação já ocupa ')
                    .($tetoBps / 100)
                    .'% (ou mais) de todas as zonas neutras do jogo — o limite antimonopólio do §04.',
            );
        }
    }

    /**
     * Custo em recursos: o Posto de Comando (Metal Bruto) mais 20 Robôs Mineradores. O custo do
     * robô vem do `building_specs` (§4.3), não hardcodado — assim acompanha o balanceamento.
     *
     * @return array<string,int>
     */
    /**
     * ⚠️ Delega, e não repete: a conta mora em `RequisitosDeOcupacao` desde o D-224, para que a
     * TELA leia exatamente o que o comando cobra. Enquanto eram dois lugares, o painel do mapa
     * anunciava "800 Metal Bruto" para uma cobrança de 1.020, e não citava as Ligas nem os
     * Componentes.
     */
    private function custoDeRecursos(): array
    {
        return app(RequisitosDeOcupacao::class)->custoDeRecursos();
    }

    /** @param array<string,int> $custo */
    private function debitarRecursos(Colony $colony, array $custo, NeutralZone $zona): void
    {
        $estoque = $colony->resources()->lockForUpdate()->get()->keyBy('resource_type');

        foreach ($custo as $recurso => $qtd) {
            $tem = $estoque->get($recurso)?->amount ?? 0;
            if ($tem < $qtd) {
                throw new DomainRuleException(
                    'recursos_insuficientes',
                    "Faltam recursos para ocupar: {$recurso} exige {$qtd}, você tem {$tem}.",
                );
            }
        }

        foreach ($custo as $recurso => $qtd) {
            $estoque[$recurso]->decrement('amount', $qtd);

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'custo_ocupacao',
                'amount' => -$qtd,
                'resource_type' => $recurso,
                'ref' => "zona:{$zona->id}:ocupacao",
                'created_at' => now(),
            ]);
        }
    }

    private function debitarFert(Colony $colony, int $fert, NeutralZone $zona): void
    {
        $micro = $fert * self::MICRO;

        $pagou = Colony::whereKey($colony->id)
            ->where('fert_micro', '>=', $micro)
            ->decrement('fert_micro', $micro);

        if ($pagou === 0) {
            throw new DomainRuleException(
                'fert_insuficiente',
                "Faltam Fert\$ para o Posto de Comando: exige {$fert}.",
            );
        }

        Ledger::create([
            'colony_id' => $colony->id,
            'type' => 'custo_ocupacao',
            'amount' => -$micro,
            'resource_type' => null,
            'ref' => "zona:{$zona->id}:posto",
            'created_at' => now(),
        ]);
    }
}
