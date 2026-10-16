<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Auditoria;
use App\Domain\Reconhecimento\AbrirMissaoPlanetaria;
use App\Domain\Reconhecimento\Gagarin;
use App\Domain\Reconhecimento\Registro;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\MissaoDeReconhecimento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * O Gagarin e as Missões de Reconhecimento no painel (D-282).
 *
 * O operador mexe nos números de partida, pausa para manutenção e retoma. **Não edita resultado**
 * (§2.1): uma missão planetária nasce com o resultado lacrado, e a única correção prevista é reabrir a
 * fase em curso, com justificativa no registro público (§2.5).
 *
 * Abrir uma missão planetária é ato do Dono, como os Eventos (D-232): uma rota nova entra no
 * Espaçoporto para o mundo inteiro e não se desfaz.
 */
class ReconhecimentoController extends Controller
{
    public function index(Gagarin $gagarin): View
    {
        return view('admin.reconhecimento', [
            'config' => $gagarin->config(),
            'contagem' => $gagarin->contagem(now()),
            'ativo' => $gagarin->ativo(),
            'pausado' => $gagarin->pausado(),
            'abertura' => $gagarin->abertura(),
            'proximoBoletim' => $gagarin->proximoBoletim(),
            'ultimaRota' => $gagarin->ultimaRotaNova(),
            'luas' => DB::table('luas')->orderBy('ordem')->get(),
            'missoes' => MissaoDeReconhecimento::orderByDesc('id')->limit(20)->get(),
            'registro' => DB::table('registro_de_reconhecimento')->orderByDesc('id')->limit(40)->get(),
            'faixas' => Gagarin::FAIXAS,
        ]);
    }

    public function config(Request $request, Auditoria $auditoria): RedirectResponse
    {
        $dados = $request->validate([
            'servidor_aberto_em' => ['nullable', 'date'],
            'limiar_jogadores' => ['required', 'integer', 'min:1', 'max:1000000'],
            'limiar_dias' => ['required', 'integer', 'min:0', 'max:3650'],
            // §2.1: "a cada 2 a 4 dias".
            'boletim_intervalo_horas' => ['required', 'integer', 'min:48', 'max:96'],
            'tarefas_por_dia' => ['required', 'integer', 'min:1', 'max:10'],
            'xp_por_tarefa' => ['required', 'integer', 'min:0', 'max:100000'],
            'meta_de_tarefas' => ['required', 'integer', 'min:0', 'max:100000'],
            'viagens_na_abertura' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        DB::table('gagarin_settings')->where('id', 1)->update($dados + ['updated_at' => now()]);
        $auditoria->registrar('reconhecimento.config', 'Números do Gagarin atualizados: '.json_encode($dados, JSON_UNESCAPED_UNICODE));

        return back()->with('ok', 'Números do Gagarin salvos. Valem para as próximas missões; as em curso guardam a meta com que nasceram.');
    }

    public function planetaria(Request $request, AbrirMissaoPlanetaria $abrir, Auditoria $auditoria): RedirectResponse
    {
        abort_unless(auth('admin')->user()->ehDono(), 403, 'Abrir uma rota nova é ato do Dono.');

        $dados = $request->validate([
            'setor' => ['required', 'string', 'max:60'],
            'anomalia' => ['required', 'in:gravitacional,energetica,espectral'],
            'faixa' => ['required', 'in:proxima,media,distante,remota'],
            'voo_horas' => ['required', 'integer', 'min:1', 'max:31'],
            'chave' => ['required', 'string', 'max:30'],
            'nome' => ['required', 'string', 'max:40'],
            'distancia_minutos' => ['required', 'integer', 'min:1', 'max:100000'],
            'risco' => ['required', 'in:nenhum,baixo,alto'],
            'risco_chance' => ['required', 'numeric', 'min:0', 'max:100'],
            'risco_perda' => ['required', 'numeric', 'min:0', 'max:100'],
            'exporta' => ['required', 'string', 'max:200'],
            'importa' => ['required', 'string', 'max:200'],
            'precos' => ['required', 'string', 'max:5000'],
        ]);

        $resultado = [
            'chave' => $dados['chave'],
            'nome' => $dados['nome'],
            'distancia_minutos' => (int) $dados['distancia_minutos'],
            'risco' => $dados['risco'],
            'risco_chance_bps' => (int) round((float) $dados['risco_chance'] * 100),
            'risco_perda_bps' => (int) round((float) $dados['risco_perda'] * 100),
            'exporta' => $dados['exporta'],
            'importa' => $dados['importa'],
            'precos' => $this->precos($dados['precos']),
        ];

        try {
            $m = $abrir->handle($resultado, $dados['setor'], $dados['anomalia'], $dados['faixa'], (int) $dados['voo_horas'], auth('admin')->id());
        } catch (DomainRuleException $e) {
            return back()->withInput()->with('erro', $e->getMessage());
        }

        $auditoria->registrar('reconhecimento.planetaria', "Missão planetária #{$m->id} aberta em {$m->setor}, lacrada com {$m->assinatura}.");

        return back()->with('ok', "Missão planetária aberta em {$m->setor}. O resultado está lacrado: {$m->assinatura}.");
    }

    /** Pausa ou retoma (§2.1, manutenção). Ao retomar, os prazos em curso andam o tempo da pausa. */
    public function pausa(Request $request, Registro $registro, Auditoria $auditoria): RedirectResponse
    {
        $dados = $request->validate(['justificativa' => ['required', 'string', 'min:5', 'max:300']]);
        $config = DB::table('gagarin_settings')->find(1);
        $agora = now();

        if ($config->pausado_em === null) {
            DB::table('gagarin_settings')->where('id', 1)->update(['pausado_em' => $agora, 'updated_at' => $agora]);
            $evento = 'pausa';
            $msg = 'Gagarin pausado.';
        } else {
            $segundos = (int) Carbon::parse($config->pausado_em)->diffInSeconds($agora);
            foreach (MissaoDeReconhecimento::whereNull('concluida_em')->whereNotNull('fase_ate')->get() as $m) {
                $m->update(['fase_ate' => $m->fase_ate->copy()->addSeconds($segundos)]);
            }
            DB::table('gagarin_settings')->where('id', 1)->update(['pausado_em' => null, 'updated_at' => $agora]);
            $evento = 'retomada';
            $msg = 'Gagarin retomado; os prazos em curso andaram o tempo da pausa.';
        }

        foreach (MissaoDeReconhecimento::whereNull('concluida_em')->get() as $m) {
            $registro->anotar($m, $evento, $dados['justificativa'], $agora);
        }
        $auditoria->registrar("reconhecimento.{$evento}", $dados['justificativa']);

        return back()->with('ok', $msg);
    }

    /**
     * Reabre a fase em curso de uma missão: o prazo volta a correr do zero (§2.5: "a equipe pode
     * corrigir bug de implementação, mas a correção exige justificativa e reabertura da fase afetada").
     * O resultado não muda.
     */
    public function reabrir(Request $request, MissaoDeReconhecimento $missao, Gagarin $gagarin, Registro $registro, Auditoria $auditoria): RedirectResponse
    {
        $dados = $request->validate(['justificativa' => ['required', 'string', 'min:10', 'max:300']]);

        if ($missao->concluida_em) {
            return back()->with('erro', 'A missão já terminou: o resultado foi revelado e não se reabre.');
        }

        $agora = now();
        $missao->update(['fase_desde' => $agora, 'fase_ate' => $gagarin->fim($missao, $missao->fase, $agora)]);
        $registro->anotar($missao, 'reaberta', "Fase {$missao->fase} reaberta: {$dados['justificativa']}", $agora);
        $auditoria->registrar('reconhecimento.reabrir', "Missão #{$missao->id}, fase {$missao->fase}: {$dados['justificativa']}");

        return back()->with('ok', "Fase {$missao->fase} reaberta, com a justificativa no registro público.");
    }

    /** `recurso:compra:venda` em Fert$ por unidade, um por linha — o formato do planeta no Espaçoporto. */
    private function precos(string $texto): array
    {
        $codigos = DB::table('resource_types')->pluck('code')->all();
        $precos = [];

        foreach (preg_split('/\R/', $texto) as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }

            [$recurso, $compra, $venda] = array_pad(array_map('trim', explode(':', $linha)), 3, '');
            $valido = fn ($v) => $v === '' || (is_numeric($v) && (float) $v >= 0);

            if (! in_array($recurso, $codigos, true) || ! $valido($compra) || ! $valido($venda) || ($compra === '' && $venda === '')) {
                throw ValidationException::withMessages(['precos' => "Linha inválida: «{$linha}». Use recurso:compra:venda."]);
            }

            $precos[$recurso] = [
                'compra_micro' => $compra === '' ? null : (int) round((float) $compra * Colony::MICRO_POR_FERT),
                'venda_micro' => $venda === '' ? null : (int) round((float) $venda * Colony::MICRO_POR_FERT),
            ];
        }

        return $precos;
    }
}
