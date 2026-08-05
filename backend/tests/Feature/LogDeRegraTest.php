<?php

namespace Tests\Feature;

use App\Exceptions\DomainRuleException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Violação de regra de jogo não é defeito do servidor, e não vai para o log (2026-08-05).
 *
 * Em produção o `laravel.log` chegou a 90 MB, e ~79 linhas por dia eram uma única
 * `DomainRuleException` — "Falta energia para esta viagem" — gravada em nível ERROR por um ator
 * repetindo o mesmo despacho impossível a cada 20 minutos. O nível mentia sobre a gravidade e o
 * volume escondia erro de verdade.
 *
 * Os dois lados são testados de propósito: silenciar demais seria pior do que o barulho.
 */
class LogDeRegraTest extends TestCase
{
    private function reportar(\Throwable $e): void
    {
        app(ExceptionHandler::class)->report($e);
    }

    public function test_violacao_de_regra_nao_e_gravada_no_log(): void
    {
        Log::spy();

        $this->reportar(new DomainRuleException('sem_energia', 'Falta energia para esta viagem.'));

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('log');
    }

    /*
     * O outro lado: a exceção que NÃO é regra de jogo continua sendo gravada. Sem este teste, um
     * `dontReport` largo demais passaria despercebido — e o sintoma seria a produção emudecer
     * justamente no dia em que algo quebrasse de verdade.
     */
    public function test_erro_inesperado_continua_indo_para_o_log(): void
    {
        Log::spy();

        $this->reportar(new RuntimeException('o banco caiu'));

        Log::shouldHaveReceived('error')->once();
    }

    /* A resposta ao jogador não muda: 422 com o código estável que o front lê. */
    public function test_a_regra_continua_devolvendo_422_com_codigo(): void
    {
        $resposta = (new DomainRuleException('sem_energia', 'Falta energia para esta viagem.'))->render();

        $this->assertSame(422, $resposta->getStatusCode());
        $this->assertSame(
            ['code' => 'sem_energia', 'message' => 'Falta energia para esta viagem.'],
            $resposta->getData(true),
        );
    }
}
