<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma tecnologia do catálogo de pesquisa (A2.3).
 *
 * ⚠️ Todo número aqui é **HIPÓTESE**. O GDD não publica árvore, custo nem tempo — o
 * `Funcoes::CATALOGO` registra isso desde sempre na nota do Laboratório. O `BALANCEAMENTO.md` §8.1
 * lista as variáveis, e o critério de saída da trilha A2.S proíbe promovê-las sem simulação.
 */
class Technology extends Model
{
    protected $table = 'technologies';

    protected $fillable = [
        'chave', 'nome', 'descricao', 'trilha', 'requer_technology_id',
        'custo_json', 'duracao_segundos', 'nivel_maximo', 'laboratorio_minimo',
        'efeitos_json', 'ativa', 'versao',
    ];

    protected $casts = [
        'custo_json' => 'array',
        'efeitos_json' => 'array',
        'duracao_segundos' => 'integer',
        'nivel_maximo' => 'integer',
        'laboratorio_minimo' => 'integer',
        'ativa' => 'boolean',
        'versao' => 'integer',
    ];

    /** As oito trilhas iniciais da A2.3. "Espacial" fica preparada e não entra nesta entrega. */
    public const TRILHAS = [
        'energia' => 'Energia',
        'biosfera' => 'Sobrevivência/Biosfera',
        'industria' => 'Indústria',
        'logistica' => 'Logística',
        'comercio' => 'Comércio',
        'ciencia' => 'Ciência',
        'defesa' => 'Defesa',
        'territorio' => 'Território',
    ];

    public function requisito(): BelongsTo
    {
        return $this->belongsTo(self::class, 'requer_technology_id');
    }
}
