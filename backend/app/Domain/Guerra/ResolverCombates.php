<?php

namespace App\Domain\Guerra;

use App\Models\Colony;
use App\Models\Combat;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A rodada de 10 minutos (GDD §27.5, §27.6, §28.10; docs/decisoes.md D-66). Roda no tick.
 *
 * Os **quatro** ataques compartilham esta máquina — é o que o §28.10 diz na primeira frase: "todos
 * os tipos de ataque usam como base comum a mecânica de rodadas de 10 minutos". O que muda é o que
 * a rodada FAZ.
 *
 * ── O dano, e a palavra que mudou ────────────────────────────────────────────────────────────────
 *
 * O §27.5 escreve:
 *
 *     Dano ao defensor por rodada = (Força Ofensiva / Força Total) × 15% × Força Defensiva *atual*
 *
 * À letra, isso **nunca termina**: se o dano se recalcula sobre a força que restou, ela decai
 * geometricamente e não chega a zero. Conferido com os números do próprio documento — no cenário
 * "Ataque 2.000 × Defesa 500", que o GDD estima em ~4 rodadas, a defesa ainda tem 92 pontos na
 * rodada 12.
 *
 * **O dano sai da força INICIAL** (D-66, arbitragem 8): calculado uma vez, com que os dois lados
 * entraram. É constante, a força cai linearmente, e a batalha termina sozinha — sem piso inventado.
 * E reproduz *exatamente* a estimativa do próprio GDD no cenário equilibrado (1.000 × 800 = 12
 * rodadas, 120 min). Quem escreveu aquela tabela calculou assim e escreveu "atual" no texto.
 *
 * ── Como as baixas se distribuem ─────────────────────────────────────────────────────────────────
 *
 * O §27.6 manda distribuí-las "proporcionalmente entre as unidades presentes". Aplicamos a **mesma
 * fração de HP a todas** — e isso *é* a distribuição proporcional, com uma propriedade que fecha a
 * conta sozinha: como a força de cada unidade é `base × hp`, tirar a fração `f` do HP de todas tira
 * exatamente `f × F` da força total. A força restante é `F − dano`, sem sobra e sem resto.
 */
class ResolverCombates
{
    private const CHEIO = 10000;

    public function __construct(
        private Forcas $forcas,
        private Protegido $protegido,
        private Sorteio $sorteio,
    ) {}

    /** @return int quantos combates avançaram */
    public function handle(?Carbon $agora = null): int
    {
        $agora ??= now();

        $ids = Combat::whereIn('status', ['marchando', 'em_curso'])
            ->where('proxima_rodada_at', '<=', $agora)
            ->orderBy('proxima_rodada_at')
            ->pluck('id');

        $avancados = 0;

        foreach ($ids as $id) {
            if ($this->avancar($id, $agora)) {
                $avancados++;
            }
        }

        return $avancados;
    }

    private function avancar(int $id, Carbon $agora): bool
    {
        return DB::transaction(function () use ($id, $agora) {
            $combate = Combat::whereKey($id)->lockForUpdate()->first();

            if (! $combate || ! $combate->vivo()) {
                return false;
            }

            $zona = NeutralZone::whereKey($combate->zone_id)->lockForUpdate()->first();

            if (! $zona) {
                $combate->update(['status' => 'expirado']);

                return false;
            }

            /*
             * A zona já é dele. O §27.10 deixa **outros** jogadores atacarem a mesma zona sem
             * restrição, então dois exércitos podem estar a caminho dela — e o primeiro a vencer
             * muda o dono debaixo do segundo. Se o segundo for o próprio dono novo (ele mandou dois
             * ataques), não há mais o que atacar: o exército volta para casa.
             *
             * Sem esta guarda ele encontraria uma zona sem defensores, "venceria" e a tomaria de si
             * mesmo — zerando a produtividade dela outra vez, de graça.
             */
            if ($zona->owner_colony_id === $combate->attacker_colony_id) {
                $this->recolherSobreviventes($combate);
                $combate->update(['status' => 'expirado', 'proxima_rodada_at' => null]);

                return true;
            }

            if ($combate->status === 'marchando') {
                $this->chegar($combate, $zona, $agora);
            }

            /*
             * Um laço, e não uma rodada só: o tick roda de minuto em minuto, mas pode atrasar (uma
             * fila travada, o servidor reiniciando). Sem o laço, um tick perdido faria a batalha
             * "pular" tempo em vez de andar — e um combate de 12 rodadas levaria 12 ticks vivos, não
             * 120 minutos de relógio. As rodadas vencidas se resolvem todas agora, em ordem.
             */
            $limite = 500;   // guarda contra laço infinito: 500 rodadas são 83 h de combate.

            while ($combate->vivo()
                && $combate->proxima_rodada_at !== null
                && $combate->proxima_rodada_at->lte($agora)
                && $limite-- > 0
            ) {
                $this->rodada($combate, $zona, $agora);
                $zona->refresh();
            }

            return true;
        });
    }

    /**
     * A marcha acabou: as unidades entram em campo e a força inicial é congelada.
     *
     * É aqui que o cerco começa a morder — ele não entra na zona, ele a fecha (§28.10).
     */
    private function chegar(Combat $combate, NeutralZone $zona, Carbon $agora): void
    {
        Unit::where('combat_id', $combate->id)
            ->where('status', 'marchando')
            ->update(['status' => 'em_combate', 'arrives_at' => null]);

        $combate->status = 'em_curso';

        if ($combate->tipo === 'cerco') {
            // "O bloqueio ocorre apenas nas rotas externas" — a zona fica fechada, e após 30 min
            // (as 3 rodadas do §28.10) o depósito para de aceitar. O defensor tem 48 h.
            $zona->update(['sieged_at' => $agora]);

            $combate->prazo_at = $agora->copy()->addHours(Combat::CERCO_HORAS);
        }

        $this->congelarForcas($combate, $zona);

        $combate->save();
    }

    /**
     * Congela a força dos dois lados e o dano por rodada que dela sai.
     *
     * Chamado na chegada **e de novo a cada reforço**: o §27.5 quer que "reforços tardios possam
     * ainda mudar o resultado", e um dano congelado para sempre na primeira rodada os tornaria
     * decorativos. Quem chega recalcula a conta a partir da força nova.
     */
    private function congelarForcas(Combat $combate, NeutralZone $zona): void
    {
        $r = $combate->resultado ?? [];

        $fo = $this->forcas->ofensiva($combate);
        $fd = $this->forcas->defensiva($zona, (bool) ($r['defensor_offline'] ?? false));
        $ft = $fo + $fd;

        $r['forca_ofensiva'] = $fo;
        $r['forca_defensiva'] = $fd;

        // O dano de cada lado, por rodada. Constante daqui em diante (D-66, arbitragem 8).
        $r['dano_ao_defensor'] = $ft > 0 ? intdiv($fo * Combat::DANO_BPS * $fd, $ft * self::CHEIO) : 0;
        $r['dano_ao_atacante'] = $ft > 0 ? intdiv($fd * Combat::DANO_BPS * $fo, $ft * self::CHEIO) : 0;

        $combate->resultado = $r;
    }

    private function rodada(Combat $combate, NeutralZone $zona, Carbon $agora): void
    {
        $combate->rodada++;
        $combate->proxima_rodada_at = $combate->proxima_rodada_at
            ->copy()
            ->addMinutes(Combat::RODADA_MINUTOS);

        match ($combate->tipo) {
            'invasao' => $this->rodadaDeInvasao($combate, $zona),
            'cerco' => $this->rodadaDeCerco($combate, $zona, $agora),
            'sabotagem' => $this->rodadaDeSabotagem($combate, $zona),
            'apreensao' => $this->rodadaDeApreensao($combate, $zona, $agora),
            default => $combate->status = 'expirado',
        };

        $combate->save();
    }

    // ── Invasão Direta (§27.5–27.8) ─────────────────────────────────────────────────────────────

    private function rodadaDeInvasao(Combat $combate, NeutralZone $zona): void
    {
        $atacantes = $this->atacantes($combate);
        $defensores = $this->defensores($zona);

        $r = $combate->resultado;

        $forcaAtk = $atacantes->sum(fn (Unit $u) => $u->ataque());

        /*
         * ⚠️ A força defensiva do denominador tem de ser a EFETIVA — com o bônus das construções e
         * o +20% de offline —, e não a soma crua das unidades.
         *
         * O dano foi calculado sobre a força efetiva (`(Fo/Ft) × 15% × Fd_efetiva`). Se a fração
         * perdida saísse da força crua, ela seria maior na exata proporção do bônus, e a Muralha, a
         * Torre e o Bastião **não protegeriam nada**: o bônus se cancelaria contra si mesmo. Foi
         * assim que este método nasceu, e estava errado.
         */
        $forcaDef = $this->forcas->defensiva($zona, (bool) ($r['defensor_offline'] ?? false));

        // Zona sem ninguém de pé: toma-se sem luta. Acontece quando o dono retirou tudo.
        if ($forcaDef <= 0) {
            $this->vitoriaDoAtacante($combate, $zona);

            return;
        }

        if ($forcaAtk <= 0) {
            $this->exercitoRepelido($combate);

            return;
        }

        $this->aplicarDano($defensores, $forcaDef, (int) $r['dano_ao_defensor']);
        $this->aplicarDano($atacantes, $forcaAtk, (int) $r['dano_ao_atacante']);

        // Reconta depois de a poeira baixar. Os dois podem cair na mesma rodada: se ambos zeram, o
        // defensor mantém a zona — quem ataca tem de VENCER, e não apenas empatar (§27.8: "o
        // atacante vence quando a Força Defensiva restante chega a zero"; se ele também morreu, não
        // há quem assuma o controle).
        $defRestante = $this->defensores($zona)->sum(fn (Unit $u) => $u->defesa());
        $atkRestante = $this->atacantes($combate)->sum(fn (Unit $u) => $u->ataque());

        if ($atkRestante <= 0) {
            $this->exercitoRepelido($combate);

            return;
        }

        if ($defRestante <= 0) {
            $this->vitoriaDoAtacante($combate, $zona);
        }
    }

    /**
     * O atacante tomou a zona (§27.8, com a correção da v3.2).
     *
     * Saqueia **50% do estoque não protegido** — não do total. E os outros 50% **permanecem no
     * depósito**: a v3.0 dizia que eram destruídos, e a tabela de precedência da seção 0 dá ganho à
     * v3.2 ("não há destruição automática adicional"). Ver D-66.
     *
     * O butim vai **direto para a colônia do atacante**: o exército o carrega. Não exigimos veículo,
     * e não cobramos tributo — saque não é entrega comercial, e o §25.2 tributa comércio.
     */
    private function vitoriaDoAtacante(Combat $combate, NeutralZone $zona): void
    {
        $butim = $this->saquear($combate, $zona, Combat::SAQUE_BPS, "combate:{$combate->id}");

        /*
         * A zona muda de dono, e o novo dono **espera o tempo de ocupação** antes de extrair
         * (§27.9: "o atacante que tomou a zona ainda precisa aguardar o tempo mínimo de ocupação
         * antes de começar a extrair"). O relógio da extração parte daí, senão ele receberia
         * retroativamente o que rendeu enquanto era do outro.
         */
        $produtiva = now()->addHours(NeutralZone::OCUPACAO_HORAS);

        $zona->update([
            'owner_colony_id' => $combate->attacker_colony_id,
            'status' => 'ocupada',
            'occupied_at' => now(),
            'protected_until' => null,   // zona conquistada não herda proteção de novato
            'productive_at' => $produtiva,
            'last_extraction_at' => $produtiva,
            'sieged_at' => null,
        ]);

        // A guarnição do derrotado que sobreviveu é destruída com a zona: ela não tem para onde
        // recuar. Quem defendia e ainda respira só sobreviveria se o defensor tivesse ABANDONADO
        // (§27.9) — e aí o combate não chega aqui.
        Unit::where('zone_id', $zona->id)->delete();

        // O exército vencedor volta para casa, ferido (§27.6). Os mortos ficam no campo.
        $this->recolherSobreviventes($combate);

        $combate->status = 'vitoria_atacante';
        $combate->proxima_rodada_at = null;
        $combate->resultado = array_merge($combate->resultado, [
            'saque' => $butim['total'],
            'saque_bruto' => $butim['bruto'],
            'saque_refinado' => $butim['refinado'],
            'mineral' => $zona->mineral,
            'rodadas' => $combate->rodada,
        ]);
    }

    /** O ataque foi repelido: a Força Ofensiva chegou a zero (§27.8). A zona não muda de dono. */
    private function exercitoRepelido(Combat $combate): void
    {
        $this->recolherSobreviventes($combate);

        $combate->status = 'repelido';
        $combate->proxima_rodada_at = null;
        $combate->resultado = array_merge($combate->resultado, ['rodadas' => $combate->rodada]);
    }

    // ── Cerco (§28.10) ──────────────────────────────────────────────────────────────────────────

    /**
     * O cerco não luta: ele espera.
     *
     * "Não envolve combate dentro da zona — bloqueio das rotas externas." A mordida está em
     * `ExtrairZonasNeutras` (depois de 30 min o depósito para de aceitar, e o que se extrai se
     * **perde**) e em `DespacharVeiculo` (nada entra nem sai). Aqui só corre o relógio das 48 h.
     *
     * Quem rompe o cerco é o **dono da zona**, mandando Sentinelas — e isso não é uma rodada deste
     * combate, é um `Atacar` do defensor contra o exército sitiante. Ver `RomperCerco`.
     */
    private function rodadaDeCerco(Combat $combate, NeutralZone $zona, Carbon $agora): void
    {
        if ($combate->prazo_at === null || $combate->prazo_at->gt($agora)) {
            return;   // ainda dentro das 48 h: o defensor tem tempo de romper.
        }

        // As 48 h venceram sem ruptura: o defensor se rende e entrega 30% do não protegido.
        $butim = $this->saquear($combate, $zona, Combat::CERCO_BPS, "cerco:{$combate->id}");

        /*
         * A zona **não muda de dono**: o cerco entrega recurso, não território. É a hierarquia que
         * o §27.8 declara — "maior que o Cerco (30%) mas sem tomar tudo, coerente com a hierarquia
         * de tipos de ataque já definida". Quem quer a zona invade.
         */
        $zona->update(['sieged_at' => null]);

        $this->recolherSobreviventes($combate);

        $combate->status = 'rendido';
        $combate->proxima_rodada_at = null;
        $combate->resultado = array_merge($combate->resultado, [
            'saque' => $butim['total'],
            'saque_bruto' => $butim['bruto'],
            'saque_refinado' => $butim['refinado'],
            'mineral' => $zona->mineral,
            'rodadas' => $combate->rodada,
        ]);
    }

    // ── Sabotagem — o Infiltrador (§28.10) ──────────────────────────────────────────────────────

    /**
     * "Cada rodada de 10 min: o Infiltrador tem 60% de chance base de atingir a estrutura-alvo se
     * não detectado. A Torre de Vigia pode interceptar a cada rodada — chance de detecção
     * proporcional ao nível da Torre."
     *
     * A ordem importa e é a do texto: **primeiro a Torre olha, depois ele age**. Detectado, cai em
     * combate normal — e o §28.10 diz que ele "provavelmente perde". No nosso motor ele perde
     * **sempre**, e isso não é descuido: o Infiltrador tem **ataque zero** (o GDD nunca publica um
     * ataque para ele), então a Força Ofensiva dele é zero e ele é repelido por definição. É o
     * desenho: a sabotagem é aposta, não batalha.
     */
    private function rodadaDeSabotagem(Combat $combate, NeutralZone $zona): void
    {
        $infiltrador = $this->atacantes($combate)->first();

        if (! $infiltrador) {
            $this->exercitoRepelido($combate);

            return;
        }

        $deteccao = $this->forcas->config()->torre_deteccao_bps_por_nivel * $zona->watchtower_level;

        if ($this->sorteio->sucesso($deteccao)) {
            $this->infiltradorMorto($combate, 'detectado_pela_torre');

            return;
        }

        if (! $this->sorteio->sucesso(Combat::INFILTRADOR_BPS)) {
            return;   // falhou a tentativa, mas passou despercebido: tenta de novo na próxima.
        }

        $this->desligarModulo($zona, (string) $combate->alvo);

        $this->recolherSobreviventes($combate);

        $combate->status = 'vitoria_atacante';
        $combate->proxima_rodada_at = null;
        $combate->resultado = array_merge($combate->resultado, [
            'sabotado' => $combate->alvo,
            'nivel_do_infiltrador' => $infiltrador->level,
            'rodadas' => $combate->rodada,
        ]);
    }

    // ── Apreensão de Módulos — o Predador (§28.10) ──────────────────────────────────────────────

    /**
     * "Resolve em 1 rodada — ação rápida de entrada e saída. Chance de sucesso = Nível do Predador
     * vs. Nível do Abrigo de Robôs defensor."
     *
     * O §28.10 manda comparar os níveis e **não publica a conta**. Arbitrada no D-66: base 50% no
     * empate, ±10% por nível de diferença, presa entre 10% e 90% — nunca há certeza nos dois
     * sentidos. É parâmetro do operador.
     *
     * Uma rodada, e acabou: deu certo, leva o módulo e há resgate; deu errado, foi detectado e
     * morre (ataque zero, como o Infiltrador).
     */
    private function rodadaDeApreensao(Combat $combate, NeutralZone $zona, Carbon $agora): void
    {
        $predador = $this->atacantes($combate)->first();

        if (! $predador) {
            $this->exercitoRepelido($combate);

            return;
        }

        $c = $this->forcas->config();

        $chance = $c->predador_base_bps
            + $c->predador_por_nivel_bps * ($predador->level - $zona->shelter_level);

        $chance = max($c->predador_min_bps, min($c->predador_max_bps, $chance));

        if (! $this->sorteio->sucesso($chance)) {
            $this->infiltradorMorto($combate, 'detectado_pelo_abrigo');

            return;
        }

        $this->desligarModulo($zona, (string) $combate->alvo);

        $this->recolherSobreviventes($combate);

        $combate->status = 'vitoria_atacante';
        $combate->proxima_rodada_at = null;
        // O resgate: 24 h para o dono reaver o módulo (§28.10). Passado o prazo, ele repara
        // normalmente — o módulo é "temporariamente removido", não destruído (v3.2).
        $combate->prazo_at = $agora->copy()->addHours(Combat::RESGATE_HORAS);
        $combate->resultado = array_merge($combate->resultado, [
            'apreendido' => $combate->alvo,
            'chance_bps' => $chance,
            'rodadas' => $combate->rodada,
        ]);
    }

    // ── peças comuns ────────────────────────────────────────────────────────────────────────────

    /**
     * O butim, creditado na colônia do atacante e debitado da zona.
     *
     * **Dois recursos, não um** (D-67): o minério bruto e o que a Refinaria de Campo já converteu. Os
     * dois estão no mesmo Depósito e os dois são butim — refinar torna a carga mais valiosa, não mais
     * segura. A repartição é proporcional ao que há de cada um.
     *
     * O exército carrega. Não exigimos veículo, e não cobramos tributo: saque não é entrega comercial,
     * e o §25.2 tributa comércio.
     *
     * @return array{bruto: int, refinado: int, total: int}
     */
    private function saquear(Combat $combate, NeutralZone $zona, int $bps, string $ref): array
    {
        $butim = $this->protegido->saqueDetalhado($zona, $bps);

        if ($butim['total'] <= 0) {
            return $butim;
        }

        $atacante = Colony::whereKey($combate->attacker_colony_id)->lockForUpdate()->first();

        $lotes = [
            [$zona->mineral, $butim['bruto'], 'deposit_amount'],
            [$zona->recursoRefinado(), $butim['refinado'], 'refined_amount'],
        ];

        foreach ($lotes as [$recurso, $qtd, $coluna]) {
            if ($recurso === null || $qtd <= 0) {
                continue;
            }

            $atacante->resources()->where('resource_type', $recurso)->increment('amount', $qtd);

            Ledger::create([
                'colony_id' => $atacante->id,
                'type' => 'saque_de_guerra',
                'amount' => $qtd,
                'resource_type' => $recurso,
                'ref' => "zona:{$zona->id}:{$ref}",
                'created_at' => now(),
            ]);

            $zona->decrement($coluna, $qtd);
        }

        return $butim;
    }

    /**
     * Tira uma estrutura de operação. É o "Módulo Operacional" da v3.2 (D-66): a estrutura para de
     * funcionar até o dono a reparar ou pagar o resgate. **Não é destruída** — "temporariamente
     * removido, que pode ser rastreado, recuperado e reparado".
     */
    private function desligarModulo(NeutralZone $zona, string $alvo): void
    {
        $offline = $zona->modules_offline ?? [];

        if (! in_array($alvo, $offline, true)) {
            $offline[] = $alvo;
        }

        $zona->update(['modules_offline' => $offline]);
    }

    /** O Infiltrador ou o Predador foi visto. Ataque zero: cai em combate e morre (§28.10). */
    private function infiltradorMorto(Combat $combate, string $como): void
    {
        Unit::where('combat_id', $combate->id)->delete();

        $combate->status = 'repelido';
        $combate->proxima_rodada_at = null;
        $combate->resultado = array_merge($combate->resultado, [
            'detectado' => $como,
            'rodadas' => $combate->rodada,
        ]);
    }

    /**
     * Tira a fração do dano do HP de todas as unidades, por igual.
     *
     * É a distribuição proporcional do §27.6, e ela fecha a conta: como a força de cada unidade é
     * `base × hp`, tirar `f` do HP de todas tira exatamente `f × F` da força total.
     */
    private function aplicarDano(Collection $unidades, int $forca, int $dano): void
    {
        if ($forca <= 0 || $dano <= 0) {
            return;
        }

        $fracaoBps = min(self::CHEIO, intdiv($dano * self::CHEIO, $forca));

        foreach ($unidades as $u) {
            $u->hp_bps = intdiv($u->hp_bps * (self::CHEIO - $fracaoBps), self::CHEIO);
            $u->save();
        }

        // Um HP que só decai por fração pode estacionar em 1 e não morrer nunca. Quem chegou ao
        // fundo morre: sem isto, uma carcaça com 0,01% de HP arrastaria a batalha para sempre.
        Unit::whereIn('id', $unidades->pluck('id'))->where('hp_bps', '<=', 0)->delete();
    }

    /**
     * O exército sobrevivente volta para casa (§27.6): "retornam ao Abrigo de Robôs (defensores) ou
     * ao Quartel (atacantes) com HP reduzido — precisam de tempo para se recuperar".
     *
     * ⚠️ **Voltam na hora, e sem tempo de recuperação.** O §27.6 fala em recuperar-se "antes de nova
     * ação" e **nunca publica quanto tempo**. Não inventamos: a unidade volta ferida, e ferida ela
     * vale menos (a força conta o HP). O freio já existe — quem ataca com um exército em frangalhos
     * ataca fraco. Se o usuário quiser uma enfermaria com relógio, é decisão dele.
     */
    private function recolherSobreviventes(Combat $combate): void
    {
        Unit::where('combat_id', $combate->id)
            ->where('hp_bps', '>', 0)
            ->update([
                'combat_id' => null,
                'status' => 'casa',
                'colony_id' => $combate->attacker_colony_id,
                'zone_id' => null,
                'arrives_at' => null,
            ]);

        Unit::where('combat_id', $combate->id)->delete();   // os que zeraram, se sobrou algum
    }

    /** @return Collection<int,Unit> */
    private function atacantes(Combat $combate): Collection
    {
        return Unit::where('combat_id', $combate->id)
            ->where('status', 'em_combate')
            ->where('hp_bps', '>', 0)
            ->get();
    }

    /** @return Collection<int,Unit> */
    private function defensores(NeutralZone $zona): Collection
    {
        return Unit::where('zone_id', $zona->id)
            ->whereIn('status', ['na_zona', 'em_combate'])
            ->where('hp_bps', '>', 0)
            ->get();
    }
}
