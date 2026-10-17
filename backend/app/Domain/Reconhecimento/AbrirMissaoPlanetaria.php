<?php

namespace App\Domain\Reconhecimento;

use App\Domain\Espacoporto\ArbitragemComOGoverno;
use App\Exceptions\DomainRuleException;
use App\Models\MissaoDeReconhecimento;
use App\Models\PlanetaNpc;
use Illuminate\Support\Facades\DB;

/**
 * O operador abre uma missão planetária com o resultado já escrito, e o jogo o lacra (§2.2, §2.5).
 *
 * O aditivo não diz que planeta a missão descobre: diz que o resultado é "pré-configurado na versão da
 * missão e assinado no log antes da abertura da fase pública". Este é o momento: o operador escreve o
 * planeta inteiro (nome, distância, risco, o que exporta e importa, os preços) e o jogo guarda o JSON e
 * o SHA-256 dele. Daí em diante a missão é pública, e o nome só aparece no fim da validação.
 *
 * As travas, todas do aditivo:
 * - o Gagarin está em operação (§2.1);
 * - não há outra missão planetária em curso: "no máximo uma missão planetária e uma lunar" (§2.1);
 * - **três boletins** desde a última rota nova, e **14 dias** desde ela (§2.5);
 * - as horas de voo da sonda cabem na faixa de distância (§2.2: Próxima 4–6 h ... Remota 19–31 h);
 * - os preços não pagam acima do Governo (D-278, D-279), nem com chave de planeta que já existe.
 */
class AbrirMissaoPlanetaria
{
    public function __construct(
        private readonly Gagarin $gagarin,
        private readonly Registro $registro,
    ) {}

    /**
     * @param  array{chave:string, nome:string, distancia_minutos:int, risco:string, risco_chance_bps:int, risco_perda_bps:int, exporta:string, importa:string, precos: array<string, array{compra_micro:?int, venda_micro:?int}>}  $resultado
     */
    public function handle(array $resultado, string $setor, string $anomalia, string $faixa, int $vooHoras, ?int $adminId = null): MissaoDeReconhecimento
    {
        // D-284: as travas e a criação andam juntas, com a linha do Gagarin travada. Um clique duplo
        // do Dono criava duas missões planetárias, e a segunda quebrava o tick na abertura da rota.
        return DB::transaction(function () use ($resultado, $setor, $anomalia, $faixa, $vooHoras, $adminId) {
            DB::table('gagarin_settings')->where('id', 1)->lockForUpdate()->first();

            return $this->abrir($resultado, $setor, $anomalia, $faixa, $vooHoras, $adminId);
        });
    }

    private function abrir(array $resultado, string $setor, string $anomalia, string $faixa, int $vooHoras, ?int $adminId): MissaoDeReconhecimento
    {
        if (! $this->gagarin->ativo()) {
            throw new DomainRuleException('gagarin_inativo', 'O Telescópio Gagarin ainda não entrou em operação.');
        }

        if (MissaoDeReconhecimento::where('tipo', MissaoDeReconhecimento::PLANETARIA)->whereNull('concluida_em')->exists()) {
            throw new DomainRuleException('planetaria_em_curso', 'Já há uma missão planetária em curso: no máximo uma por vez (§2.1).');
        }

        $config = $this->gagarin->config();
        if ((int) $config->boletins_desde_a_ultima_rota < 3) {
            throw new DomainRuleException(
                'boletins_insuficientes',
                "Uma rota nova pede três boletins do Gagarin desde a última; houve {$config->boletins_desde_a_ultima_rota}.",
            );
        }

        $ultima = $this->gagarin->ultimaRotaNova();
        if ($ultima && $ultima->copy()->addDays(14)->isFuture()) {
            throw new DomainRuleException(
                'rota_recente',
                'A última rota nova abriu em '.$ultima->format('d/m').'. A próxima só depois de 14 dias.',
            );
        }

        if (! isset(Fases::ANOMALIAS[$anomalia])) {
            throw new DomainRuleException('anomalia_invalida', 'A assinatura é gravitacional, energética ou espectral.');
        }

        [$min, $max] = Gagarin::FAIXAS[$faixa] ?? [null, null];
        if ($min === null || $vooHoras < $min || $vooHoras > $max) {
            throw new DomainRuleException(
                'voo_fora_da_faixa',
                'As horas de voo da sonda ficam na faixa: Próxima 4–6, Média 7–12, Distante 13–18, Remota 19–31.',
            );
        }

        $this->validar($resultado);
        $resultado['voo_horas'] = $vooHoras;

        return DB::transaction(function () use ($resultado, $setor, $anomalia, $faixa, $adminId, $config) {
            $agora = now();
            $m = MissaoDeReconhecimento::create([
                'tipo' => MissaoDeReconhecimento::PLANETARIA,
                'alvo' => $resultado['chave'],
                'setor' => mb_substr(trim($setor), 0, 60),
                'anomalia' => $anomalia,
                'faixa' => $faixa,
                'fase' => 'triangulacao',
                'fase_desde' => $agora,
                'fase_ate' => $agora->copy()->addHours(Gagarin::FASES[MissaoDeReconhecimento::PLANETARIA]['triangulacao']),
                'meta_de_tarefas' => (int) $config->meta_de_tarefas,
                'resultado' => $resultado,
                'assinatura' => MissaoDeReconhecimento::assinar($resultado),
                'admin_id' => $adminId,
            ]);

            $this->registro->criada($m, $agora);
            $this->gagarin->noticia(
                'Pista orbital: uma anomalia '.Fases::ANOMALIAS[$anomalia],
                'O Gagarin identificou uma assinatura '.Fases::ANOMALIAS[$anomalia]." em {$m->setor}. A triangulação pública "
                .'começou: por 48 horas, cada colono com Laboratório pode contribuir com até duas tarefas de pesquisa por dia. '
                .'O resultado já está lacrado no registro da missão, com a assinatura publicada.',
                $agora,
            );

            return $m;
        });
    }

    private function validar(array $r): void
    {
        foreach (['chave', 'nome', 'distancia_minutos', 'risco', 'exporta', 'importa'] as $campo) {
            if (! isset($r[$campo]) || $r[$campo] === '') {
                throw new DomainRuleException('resultado_incompleto', "O resultado da missão precisa de: {$campo}.");
            }
        }

        if (! preg_match('/^[a-z][a-z0-9_]{1,29}$/', (string) $r['chave'])) {
            throw new DomainRuleException('chave_invalida', 'A chave do planeta é minúscula, sem espaço nem acento.');
        }

        if (PlanetaNpc::where('chave', $r['chave'])->orWhere('nome', $r['nome'])->exists()
            || MissaoDeReconhecimento::where('alvo', $r['chave'])->exists()) {
            throw new DomainRuleException('planeta_existente', 'Já existe planeta (ou missão) com esta chave ou este nome.');
        }

        if (! in_array($r['risco'], ['nenhum', 'baixo', 'alto'], true)) {
            throw new DomainRuleException('risco_invalido', 'O risco é nenhum, baixo ou alto, como no §03.');
        }

        if (empty($r['precos'])) {
            throw new DomainRuleException('sem_precos', 'Um planeta sem preço não compra nem vende nada.');
        }

        app(ArbitragemComOGoverno::class)->conferirPlaneta($r['precos']);
    }
}
