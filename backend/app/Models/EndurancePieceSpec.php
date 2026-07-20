<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma linha do catálogo da Loja de Peças da Endurance (D-133) — preço, marco e o efeito (desconto
 * de tributo), editáveis pelo painel. A imagem não mora aqui: continua em `image_bindings`, por
 * SEÇÃO (D-132) — 4 camadas da mesma seção compartilham a mesma arte.
 */
class EndurancePieceSpec extends Model
{
    protected $fillable = [
        'peca_key', 'secao', 'secao_nome', 'camada', 'nome',
        'marco_minimo', 'preco_micro', 'desconto_tributo_bps', 'unica', 'admin_id',
    ];

    protected $casts = [
        'marco_minimo' => 'integer',
        'preco_micro' => 'integer',
        'desconto_tributo_bps' => 'integer',
        'unica' => 'boolean',
    ];
}
