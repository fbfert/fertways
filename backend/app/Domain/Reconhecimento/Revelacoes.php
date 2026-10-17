<?php

namespace App\Domain\Reconhecimento;

use App\Models\MissaoDeReconhecimento;
use App\Models\PlanetaNpc;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * O que acontece no mundo quando uma fase de reconhecimento fecha ou abre (§2.2, §2.3, §2.5).
 *
 * - **Lua, fim do mapeamento:** cada colono que contribuiu recebe uma entrada privada no Diário do
 *   Colono, com o fragmento cartográfico (§2.3). É recompensa narrativa: nunca recurso, Fert$ ou poder.
 * - **Lua, fim da validação:** a ficha é consolidada na Central de Pesquisas e Notícias e a lua passa
 *   a revelada. Ela continua "só informação até a Temporada 2" — não há pouso nem extração.
 * - **Planeta, fim da validação:** o planeta lacrado entra no Espaçoporto para todos, aberto, com os
 *   preços e o risco do resultado; nas primeiras 48 h, cada colônia faz uma viagem só para lá (o
 *   "limite de ordem" do §2.2). Quem contribuiu recebe a entrada do Diário.
 *
 * Toda revelação vai ao registro com o resultado inteiro, para conferir contra a assinatura.
 */
class Revelacoes
{
    public function __construct(
        private readonly Registro $registro,
    ) {}

    public function aoFechar(MissaoDeReconhecimento $m, CarbonInterface $agora): void
    {
        $r = $m->resultado;

        if ($m->tipo === MissaoDeReconhecimento::LUNAR && $m->fase === 'mapeamento') {
            $this->diario(
                $m,
                "Fragmento cartográfico de {$r['nome']}",
                "Você ajudou a ler a superfície de {$r['nome']}. O mapeamento confirmou: {$r['primeira_revelacao']}. "
                ."{$r['gancho']}",
                $agora,
            );
        }

        if ($m->tipo === MissaoDeReconhecimento::LUNAR && $m->fase === 'validacao') {
            DB::table('luas')->where('chave', $m->alvo)->update(['revelada_em' => $agora]);
            $this->registro->revelado($m, $agora);
            app(Gagarin::class)->noticia(
                "Ficha consolidada: a lua {$r['nome']}",
                "A Central de Pesquisas e Notícias consolidou a ficha de {$r['nome']}. Primeira revelação: "
                ."{$r['primeira_revelacao']}. Recurso associado: ".self::nomeDoRecurso($r['recurso']).". {$r['gancho']} "
                .'A lua segue sendo informação: base, mineração e conflito lunar ficam para a Temporada 2.',
                $agora,
            );
        }

        if ($m->tipo === MissaoDeReconhecimento::PLANETARIA && $m->fase === 'validacao') {
            $this->abrirRota($m, $agora);
        }
    }

    public function aoAbrir(MissaoDeReconhecimento $m, CarbonInterface $agora): void
    {
        $avisos = [
            'preparacao' => ['A sonda está sendo preparada', 'A triangulação pública terminou. A Administração aloca a sonda e publica a janela de lançamento; não há custo individual, leilão nem prioridade.'],
            'voo' => ['A sonda partiu', 'A sonda decolou rumo a '.$m->setor.'. O voo e a leitura levam algumas horas; o risco é um atributo da rota, não uma chance de a missão falhar.'],
            'validacao' => ['A sonda voltou com a leitura', 'A equipe automática consolida atmosfera, risco, exportações, importações e preços. A rota abre ao fim da validação.'],
            'janela_orbital' => ['Janela orbital lunar aberta', 'A leitura acumulada da lua continua, como preparação narrativa. Quem tem Laboratório pode seguir contribuindo.'],
        ];

        if (isset($avisos[$m->fase]) && ! ($m->tipo === MissaoDeReconhecimento::LUNAR && $m->fase === 'validacao')) {
            [$titulo, $corpo] = $avisos[$m->fase];
            app(Gagarin::class)->noticia($titulo, $corpo, $agora);
        }
    }

    private function abrirRota(MissaoDeReconhecimento $m, CarbonInterface $agora): void
    {
        $r = $m->resultado;
        $ordem = (int) PlanetaNpc::max('ordem') + 1;

        $planeta = new PlanetaNpc;
        $planeta->forceFill([
            'chave' => $r['chave'],
            'nome' => $r['nome'],
            'distancia_minutos' => (int) $r['distancia_minutos'],
            'risco' => $r['risco'],
            'exporta' => $r['exporta'],
            'importa' => $r['importa'],
            'aberto' => true,
            'ordem' => $ordem,
            'risco_chance_bps' => (int) $r['risco_chance_bps'],
            'risco_perda_bps' => (int) $r['risco_perda_bps'],
            'missao_id' => $m->id,
            'abertura_ate' => $agora->copy()->addHours(48),
        ])->save();

        /*
         * D-284: confere de novo contra o Governo na abertura. A guarda do painel já impede baixar a
         * oferta do Governo abaixo de um preço lacrado, mas uma oferta pode ter entrado por outro
         * caminho durante os dias da missão. Pela regra do D-278, sai a compra do planeta, não o
         * preço do Governo — e o registro público diz o que saiu e por quê.
         */
        $governo = DB::table('market_orders')->whereNull('colony_id')->where('side', 'sell')
            ->whereIn('status', ['aberta', 'parcial'])->pluck('price_micro', 'resource_type');

        foreach ($r['precos'] as $recurso => $p) {
            if (($p['compra_micro'] ?? null) !== null && isset($governo[$recurso]) && (int) $p['compra_micro'] > (int) $governo[$recurso]) {
                $this->registro->anotar($m, 'compra_retirada', "A compra de {$recurso} saiu da rota: o Governo vende a {$governo[$recurso]} µF$ e o planeta pagaria {$p['compra_micro']}.", $agora);
                $p['compra_micro'] = null;
            }

            if (($p['compra_micro'] ?? null) === null && ($p['venda_micro'] ?? null) === null) {
                continue;
            }

            $planeta->precos()->create([
                'resource_type' => $recurso,
                'compra_micro' => $p['compra_micro'] ?? null,
                'venda_micro' => $p['venda_micro'] ?? null,
            ]);
        }

        DB::table('gagarin_settings')->where('id', 1)->update(['boletins_desde_a_ultima_rota' => 0, 'updated_at' => $agora]);
        $this->registro->revelado($m, $agora);

        $this->diario(
            $m,
            "A rota para {$r['nome']}",
            "Você ajudou a triangular a anomalia de {$m->setor}. Ela era {$r['nome']}, e a rota acaba de abrir no Espaçoporto.",
            $agora,
        );

        app(Gagarin::class)->noticia(
            "Rota nova: {$r['nome']}",
            "A missão de reconhecimento de {$m->setor} terminou: a anomalia era {$r['nome']}. Exporta {$r['exporta']}; "
            ."importa {$r['importa']}. A rota entrou no Espaçoporto para todos. Nas primeiras 48 h, cada colônia faz uma "
            .'viagem só para lá, para ninguém monopolizar a abertura. A assinatura do resultado está no registro da missão.',
            $agora,
        );
    }

    /** Uma entrada privada do Diário para cada colônia que contribuiu com a missão. */
    private function diario(MissaoDeReconhecimento $m, string $titulo, string $corpo, CarbonInterface $agora): void
    {
        $colonias = DB::table('contribuicoes_de_reconhecimento')->where('missao_id', $m->id)
            ->distinct()->pluck('colony_id');

        foreach ($colonias as $colonyId) {
            DB::table('diario_do_colono')->insert([
                'colony_id' => $colonyId,
                'missao_id' => $m->id,
                'titulo' => mb_substr($titulo, 0, 120),
                'corpo' => $corpo,
                'created_at' => $agora,
            ]);
        }
    }

    private static function nomeDoRecurso(string $code): string
    {
        return (string) (DB::table('resource_types')->where('code', $code)->value('nome') ?? $code);
    }
}
