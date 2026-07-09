<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Violação de regra de jogo. Vira 422 com um código estável que o front pode ler. */
class DomainRuleException extends RuntimeException
{
    public function __construct(public readonly string $codigo, string $mensagem)
    {
        parent::__construct($mensagem);
    }

    public function render(): JsonResponse
    {
        return response()->json(['code' => $this->codigo, 'message' => $this->getMessage()], 422);
    }
}
