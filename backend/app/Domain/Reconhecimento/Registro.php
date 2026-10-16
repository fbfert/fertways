<?php

namespace App\Domain\Reconhecimento;

use App\Models\MissaoDeReconhecimento;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * O registro público de uma Missão de Reconhecimento (§2.1, §2.5).
 *
 * "O painel administrativo pode pausar por manutenção, mas não editar resultado sem log público." Cada
 * missão nasce com a assinatura do resultado no registro. O resultado em si só entra quando é revelado,
 * para que o planeta não vaze antes da hora, e qualquer um pode conferir que o SHA-256 dele é o mesmo
 * que estava lá desde o começo.
 */
class Registro
{
    public function criada(MissaoDeReconhecimento $m, CarbonInterface $agora): void
    {
        $this->anotar($m, 'criada', "Resultado lacrado. SHA-256: {$m->assinatura} (versão {$m->versao}).", $agora);
    }

    public function revelado(MissaoDeReconhecimento $m, CarbonInterface $agora): void
    {
        $this->anotar(
            $m,
            'revelado',
            'Resultado revelado, o mesmo que foi lacrado: '.json_encode($m->resultado, JSON_UNESCAPED_UNICODE),
            $agora,
        );
    }

    public function anotar(MissaoDeReconhecimento $m, string $evento, string $detalhe, CarbonInterface $agora): void
    {
        DB::table('registro_de_reconhecimento')->insert([
            'missao_id' => $m->id,
            'evento' => $evento,
            'detalhe' => $detalhe,
            'created_at' => $agora,
        ]);
    }
}
