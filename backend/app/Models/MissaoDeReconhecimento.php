<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma Missão de Reconhecimento (aditivo v3.4 §2, D-282): planetária ou lunar.
 *
 * O `resultado` é lacrado na criação e a `assinatura` é o SHA-256 dele. Os dois ficam públicos no
 * registro desde o primeiro minuto (§2.5): "o resultado científico é pré-configurado na versão da
 * missão e assinado no log antes da abertura da fase pública". Qualquer um pode conferir, depois da
 * revelação, que o resultado é o mesmo que estava lacrado.
 */
class MissaoDeReconhecimento extends Model
{
    public const PLANETARIA = 'planetaria';

    public const LUNAR = 'lunar';

    protected $table = 'missoes_de_reconhecimento';

    protected $fillable = [
        'tipo', 'alvo', 'setor', 'anomalia', 'faixa', 'fase', 'fase_desde', 'fase_ate',
        'tarefas', 'meta_de_tarefas', 'resultado', 'assinatura', 'versao',
        'pausada_em', 'concluida_em', 'admin_id',
    ];

    protected $casts = [
        'resultado' => 'array',
        'fase_desde' => 'datetime',
        'fase_ate' => 'datetime',
        'pausada_em' => 'datetime',
        'concluida_em' => 'datetime',
        'tarefas' => 'integer',
        'meta_de_tarefas' => 'integer',
        'versao' => 'integer',
    ];

    /** O SHA-256 do resultado, sobre o mesmo JSON que o registro publica. */
    public static function assinar(array $resultado): string
    {
        return hash('sha256', json_encode($resultado, JSON_UNESCAPED_UNICODE));
    }
}
