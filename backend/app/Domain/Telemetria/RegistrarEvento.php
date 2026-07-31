<?php

namespace App\Domain\Telemetria;

use App\Models\Colony;
use App\Models\TelemetryEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registra um evento discreto de telemetria (A2.0.1).
 *
 * ## Medir nunca pode derrubar a jogada
 *
 * Esta é a regra que dá forma à classe inteira. A telemetria vai ser chamada de dentro do despacho
 * de carga, da conclusão de upgrade, do login — caminhos que **precisam funcionar**. Se a tabela
 * encher, se o json vier torto, se um índice travar, o colono não pode perder a viagem por causa
 * do instrumento que só existe para observá-la.
 *
 * Então tudo aqui é engolido e vai para o log. É a única classe do domínio autorizada a fazer isso,
 * e a autorização é exatamente por não ser regra de jogo: nenhuma decisão do mundo depende do que
 * ela escreve. Um evento perdido é um buraco num gráfico; uma exceção propagada seria uma jogada
 * perdida.
 *
 * ⚠️ O reverso disso é que **falha de telemetria é silenciosa para o jogador e barulhenta só no
 * log**. Quem for investigar um gráfico com buraco deve procurar `telemetria:` no `laravel.log`
 * antes de concluir que ninguém jogou naquele dia.
 */
class RegistrarEvento
{
    /**
     * @param  array<string,mixed>|null  $payload  qualificadores do evento — nunca número para somar
     */
    public function handle(
        string $tipo,
        ?User $user = null,
        ?Colony $colonia = null,
        ?array $payload = null,
        string $origem = 'humano',
    ): ?TelemetryEvent {
        try {
            return TelemetryEvent::create([
                'user_id' => $user?->id,
                /*
                 * A colônia pode vir da própria colônia ou ser deduzida do jogador. Deduzir é o
                 * caso comum — quase todo evento nasce numa ação do colono, e obrigar cada chamador
                 * a carregar a colônia à mão seria convidar ao esquecimento silencioso.
                 */
                'colony_id' => $colonia?->id ?? $user?->colony?->id,
                'type' => $tipo,
                'origin' => $origem,
                'payload' => $payload,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('telemetria: evento não registrado', [
                'tipo' => $tipo,
                'user_id' => $user?->id,
                'erro' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * O que o operador faz não é jogada de jogador.
     *
     * Sem esta separação o DAU conta o admin que entrou para consertar alguma coisa, e a métrica
     * que deveria dizer "quantas pessoas jogaram" passa a dizer "quantas pessoas abriram o sistema".
     * São perguntas diferentes e a segunda não serve para nada.
     */
    public function sistema(string $tipo, ?Colony $colonia = null, ?array $payload = null): ?TelemetryEvent
    {
        return $this->handle($tipo, null, $colonia, $payload, 'sistema');
    }
}
