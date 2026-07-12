<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImageBinding;
use Illuminate\Http\JsonResponse;

/**
 * As imagens que o jogo desenha (docs/decisoes.md D-68).
 *
 * Um mapa `chave da coisa → URLs`. **Só as coisas que TÊM imagem aparecem aqui**: quem não tem
 * simplesmente não vem no mapa, e o frontend cai no hexágono colorido de sempre. É isso que faz o
 * jogo nunca ficar com buraco enquanto a arte não chega — e que permite ir preenchendo aos poucos.
 *
 * Sem autenticação de nada especial: a arte é pública, como o bundle e o favicon.
 */
class ImagesController extends Controller
{
    public function index(): JsonResponse
    {
        $mapa = [];

        foreach (ImageBinding::with('asset')->get() as $v) {
            if (! $v->asset) {
                continue;   // a imagem foi apagada e o cascade ainda não correu.
            }

            $mapa[$v->entity_key] = [
                // A pequena (264×264) é a que a cena desenha em cima do hexágono.
                'pequena' => $v->asset->url(),
                // A grande (1024×1024) é a do cartão de detalhe. Cai na pequena se não houver.
                'grande' => $v->asset->url(grande: true),
            ];
        }

        return response()->json(['images' => $mapa]);
    }
}
