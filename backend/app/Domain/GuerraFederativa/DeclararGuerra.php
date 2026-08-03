<?php

namespace App\Domain\GuerraFederativa;

use App\Domain\Chat\ContaSistema;
use App\Domain\Chat\EnviarMensagem;
use App\Domain\Eventos\Modificadores;
use App\Domain\Federacao\Aliancas;
use App\Domain\News\PublicarNoticia;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationWar;
use App\Models\WarSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Declarar guerra a outra federação (A2.10, primeira fatia).
 *
 * Cada regra daqui é uma das doze decisões do D-193, e o GDD da fase explica o porquê de cada uma.
 *
 * ## Não há recusa (decisão 4)
 *
 * Quem é declarado **está** em guerra. Poder recusar transformaria a declaração num pedido, e um
 * agressor não pede licença. A defesa é a capitulação, que vem na fatia seguinte.
 *
 * ## Declarar a uma aliada ROMPE a aliança (decisão 8)
 *
 * Automaticamente, e a tela avisa antes de confirmar. Aliada e inimiga não podem ser a mesma coisa
 * ao mesmo tempo sem que o vocabulário do jogo deixe de significar alguma coisa.
 *
 * ## ⚠️ O custo sai do FUNDO, não do bolso de quem declara (decisão 3)
 *
 * Guerra é decisão coletiva. Se o Líder pudesse declarar com o próprio dinheiro, a federação inteira
 * pagaria as consequências de uma escolha individual — e se pudesse declarar de graça, pior ainda.
 *
 * ## E o Motor de Eventos manda aqui
 *
 * A **trégua** (`guerra_declaracao`) fecha o portão, e o **custo de mobilização** (`guerra_custo`)
 * multiplica o preço. Os dois são lidos **por instante**, nunca por média — declarar é um ato, não
 * uma taxa que corre no tempo (D-194).
 */
class DeclararGuerra
{
    public function __construct(
        private readonly Aliancas $aliancas,
        private readonly Modificadores $eventos,
        private readonly EnviarMensagem $chat,
        private readonly PublicarNoticia $noticias,
    ) {}

    public function handle(Colony $autor, Federation $alvo): FederationWar
    {
        return DB::transaction(function () use ($autor, $alvo) {
            $minha = $this->federacaoDe($autor);

            if ($minha->id === $alvo->id) {
                throw new DomainRuleException('mesma_federacao', 'Uma federação não declara guerra a si mesma.');
            }

            if ($alvo->disbanded_at !== null) {
                throw new DomainRuleException('federacao_dissolvida', 'Essa federação já não existe.');
            }

            $agora = now();
            $config = WarSetting::singleton();

            /*
             * ⚠️ A trégua do Governo fecha o portão antes de qualquer outra conferência.
             *
             * É o primeiro consumidor do modificador de guerra (D-194), e a leitura é POR INSTANTE:
             * "há trégua agora?" não se responde com média de intervalo.
             */
            if ($this->eventos->guerraBloqueada(null, $agora)) {
                throw new DomainRuleException(
                    'tregua_vigente',
                    'O Governo suspendeu as declarações de guerra. Nenhuma pode ser aberta agora.',
                );
            }

            $this->conferirGuerraEmCurso($minha, $alvo);
            $this->conferirCooldown($minha, $alvo, $config, $agora);

            $custo = $this->custo($config, $agora);
            $this->debitarDoFundo($minha, $custo);

            /*
             * A aliança cai AQUI, e não antes: se alguma conferência acima recusasse a declaração, a
             * federação teria perdido a aliada por uma guerra que não aconteceu.
             */
            $this->romperAliancaSeHouver($minha, $alvo);

            $guerra = FederationWar::create([
                'declarante_id' => $minha->id,
                'alvo_id' => $alvo->id,
                'comeca_em' => $agora,
                'termina_em' => $agora->copy()->addHours((int) $config->federativa_duracao_horas),
                'status' => 'ativa',
                'declarada_por_colony_id' => $autor->id,
            ]);

            $this->anunciar($minha, $alvo, $guerra);

            return $guerra;
        });
    }

    /**
     * O custo, já com o modificador de evento aplicado.
     *
     * @return array{fert:int, niobio:int}
     */
    public function custo(?WarSetting $config = null, ?CarbonInterface $quando = null): array
    {
        $config ??= WarSetting::singleton();
        $quando ??= now();

        // Por instante: o preço é o do momento em que se declara. Ver o D-194.
        $bps = $this->eventos->em(null, Modificadores::GUERRA_CUSTO, $quando);

        return [
            'fert' => intdiv((int) $config->federativa_custo_fert_micro * $bps, 10_000),
            'niobio' => intdiv((int) $config->federativa_custo_niobio * $bps, 10_000),
        ];
    }

    /** @param array{fert:int, niobio:int} $custo */
    private function debitarDoFundo(Federation $federacao, array $custo): void
    {
        $f = Federation::whereKey($federacao->id)->lockForUpdate()->firstOrFail();

        if ((int) $f->fert_micro < $custo['fert']) {
            throw new DomainRuleException(
                'fundo_insuficiente',
                'O fundo da federação não tem Fert$ para declarar: exige '
                    .number_format($custo['fert'] / Colony::MICRO_POR_FERT, 2, ',', '.')
                    .' e tem '.number_format($f->fert_micro / Colony::MICRO_POR_FERT, 2, ',', '.').'.',
            );
        }

        /*
         * `where amount >= qtd` no UPDATE, e não leitura seguida de escrita: nunca gasta além do
         * saldo, mesmo em corrida. Mesma guarda atômica do `SacarDoFundo` e do `Tesouro::distribuir`.
         */
        $baixou = DB::table('federation_holdings')
            ->where('federation_id', $f->id)
            ->where('resource_type', 'niobio_alienigena')
            ->where('amount', '>=', $custo['niobio'])
            ->decrement('amount', $custo['niobio']);

        if ($baixou === 0) {
            throw new DomainRuleException(
                'niobio_insuficiente',
                "O fundo não tem os {$custo['niobio']} de Nióbio Alienígena que a declaração exige.",
            );
        }

        $f->decrement('fert_micro', $custo['fert']);

        DB::table('federation_ledger')->insert([
            'federation_id' => $f->id,
            'colony_id' => null,
            'type' => 'debito',
            'amount' => -$custo['niobio'],
            'resource_type' => 'niobio_alienigena',
            'ref' => 'guerra:declaracao:'.$f->id.':'.now()->getTimestampMs(),
            'created_at' => now(),
        ]);
    }

    private function conferirGuerraEmCurso(Federation $a, Federation $b): void
    {
        if (FederationWar::entre($a->id, $b->id)->where('status', 'ativa')->exists()) {
            throw new DomainRuleException('ja_em_guerra', 'Vocês já estão em guerra.');
        }
    }

    /**
     * O cooldown do PAR (GDD §10).
     *
     * ⚠️ Sem ele, uma federação forte mantém outra em guerra permanente: declara de novo assim que o
     * prazo acaba. É o problema do assédio, resolvido com relógio — e o relógio é do par, não da
     * federação, senão puniria quem foi atacado.
     */
    private function conferirCooldown(Federation $a, Federation $b, WarSetting $config, $agora): void
    {
        $horas = (int) $config->federativa_cooldown_horas;

        $recente = FederationWar::entre($a->id, $b->id)
            ->where('status', '!=', 'ativa')
            ->where('termina_em', '>', $agora->copy()->subHours($horas))
            ->orderByDesc('termina_em')
            ->first();

        if ($recente) {
            $livre = $recente->termina_em->copy()->addHours($horas);

            throw new DomainRuleException(
                'cooldown',
                'Vocês guerrearam há pouco. Nova declaração entre as duas só a partir de '
                    .$livre->format('d/m H:i').'.',
            );
        }
    }

    private function romperAliancaSeHouver(Federation $minha, Federation $alvo): void
    {
        if (! $this->aliancas->saoAliadas($minha->id, $alvo->id)) {
            return;
        }

        DB::table('federation_alliances')
            ->where('menor_id', min($minha->id, $alvo->id))
            ->where('maior_id', max($minha->id, $alvo->id))
            ->delete();
    }

    /**
     * O anúncio (GDD §3): público e imediato.
     *
     * ⚠️ Guerra secreta é emboscada, e emboscada num jogo assíncrono pune quem estava dormindo. Vai
     * ao mural do planeta **e** ao chat de quem pode reagir dos dois lados — a mesma lição do D-121,
     * que teve de espalhar avisos por seis serviços porque expulsão acontecia em silêncio.
     */
    private function anunciar(Federation $minha, Federation $alvo, FederationWar $guerra): void
    {
        $this->noticias->publicar(
            "Guerra declarada: {$minha->name} contra {$alvo->name}",
            "A federação {$minha->name} declarou guerra a {$alvo->name}. A campanha vai até "
                .$guerra->termina_em->format('d/m/Y H:i').'.',
            'Ministério das Relações',
        );

        foreach ([$minha, $alvo] as $f) {
            $destinos = Colony::where('federation_id', $f->id)
                ->whereIn('federation_role', [Federation::LIDER, Federation::DIPLOMATA])
                ->with('user')->get();

            foreach ($destinos as $colonia) {
                if ($usuario = $colonia->user) {
                    $this->chat->sistema(
                        ContaSistema::federacao(),
                        $usuario,
                        "Guerra entre {$minha->name} e {$alvo->name}, até "
                            .$guerra->termina_em->format('d/m H:i').'.',
                    );
                }
            }
        }
    }

    /** Líder ou Diplomata — a mesma permissão da aliança (D-182). Quem fala com fora é um só. */
    private function federacaoDe(Colony $autor): Federation
    {
        $colonia = Colony::whereKey($autor->id)->lockForUpdate()->first();

        if (! $colonia?->federation_id) {
            throw new DomainRuleException('sem_federacao', 'Você não está em uma federação.');
        }

        if (! $colonia->podeConvidarParaFederacao()) {
            throw new DomainRuleException(
                'sem_permissao',
                'Só o Líder ou o Diplomata declaram guerra por uma federação.',
            );
        }

        return Federation::whereKey($colonia->federation_id)->firstOrFail();
    }
}
