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
 *
 * ## ⚠️ O evento do caminho de FALHA não pode ser escrito na transação
 *
 * Este foi um defeito real, achado em produção em 2026-07-31 e não por teste: `falta_de_energia` e
 * `falta_de_insumo` são registrados logo antes de um `throw`, e esse throw está **dentro de um
 * `DB::transaction`**. A exceção sobe, a transação reverte — e leva o evento junto. As duas métricas
 * que o D-165 chamou de "mais valiosas da fase" gravavam no vazio, sem erro, sem aviso, sem sintoma.
 *
 * O sintoma que denunciou: o `laravel.log` de produção registrou a exceção às 22:55, e
 * `telemetry_events` não tinha uma linha — **nem um aviso `telemetria:`**, o que provava que a
 * chamada acontecera e o INSERT sumira depois.
 *
 * A cura é adiar — e **quem pede o adiamento é o chamador**, com `adiar: true`. Detectar transação
 * sozinho não funciona: o `RefreshDatabase` dos testes envolve cada teste numa transação, e o
 * detector diria "estou em transação" o tempo todo. Quem sabe que está num caminho de falha é o
 * código do domínio, não o framework.
 *
 * O evento adiado vai para um buffer e é gravado quando a requisição (ou o comando) termina, fora
 * de qualquer transação. Perder um evento por queda do processo continua aceitável — é a mesma
 * tolerância que esta classe já tinha.
 */
class RegistrarEvento
{
    /**
     * @param  array<string,mixed>|null  $payload  qualificadores do evento — nunca número para somar
     * @param  bool  $adiar  true quando o registro está num caminho que vai lançar exceção dentro de
     *                       uma transação — sem isso o evento morre no rollback. Ver o docblock.
     */
    public function handle(
        string $tipo,
        ?User $user = null,
        ?Colony $colonia = null,
        ?array $payload = null,
        string $origem = 'humano',
        bool $adiar = false,
    ): ?TelemetryEvent {
        $linha = [
            'user_id' => $user?->id,
            /*
             * A colônia pode vir da própria colônia ou ser deduzida do jogador. Deduzir é o caso
             * comum — quase todo evento nasce numa ação do colono, e obrigar cada chamador a
             * carregar a colônia à mão seria convidar ao esquecimento silencioso.
             */
            'colony_id' => $colonia?->id ?? $user?->colony?->id,
            'type' => $tipo,
            'origin' => $origem,
            'payload' => $payload,
            'created_at' => now(),
        ];

        /*
         * Caminho de falha: ADIA. Ver o ⚠️ no docblock — escrever aqui morreria no rollback que a
         * própria exceção provoca, segundos depois.
         */
        if ($adiar) {
            $this->enfileirar($linha);

            return null;
        }

        try {
            return TelemetryEvent::create($linha);
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
     * ⚠️ Estado de INSTÂNCIA, e a classe é **singleton** no contêiner — ver `AppServiceProvider`.
     *
     * Foi `static` por um momento, e o preço apareceu na hora: estático sobrevive entre testes do
     * mesmo processo, e um teste passou a depender do que o anterior deixou no buffer. Passava
     * sozinho e reprovava na suíte, que é o pior modo de falhar.
     *
     * Com estado de instância mais singleton, o contêiner é recriado a cada teste e o buffer morre
     * junto — sem ninguém precisar lembrar de limpá-lo.
     *
     * @var list<array<string,mixed>>
     */
    private array $pendentes = [];

    private bool $agendado = false;

    /**
     * Guarda para escrever depois, e agenda a descarga uma única vez.
     *
     * `terminating` roda ao fim da requisição HTTP e ao fim do comando de console — nos dois casos
     * já fora de qualquer transação de domínio, que é exatamente o que se precisa.
     */
    private function enfileirar(array $linha): void
    {
        $this->pendentes[] = $linha;

        if (! $this->agendado) {
            $this->agendado = true;
            app()->terminating(fn () => $this->descarregar());
        }
    }

    /**
     * Grava o que ficou pendente. Público porque teste e comando precisam forçar a descarga sem
     * esperar o ciclo de vida da aplicação.
     */
    public function descarregar(): int
    {
        if ($this->pendentes === []) {
            return 0;
        }

        $linhas = $this->pendentes;
        $this->pendentes = [];
        $this->agendado = false;

        try {
            foreach ($linhas as $l) {
                TelemetryEvent::create($l);
            }

            return count($linhas);
        } catch (Throwable $e) {
            Log::warning('telemetria: descarga falhou', ['quantos' => count($linhas), 'erro' => $e->getMessage()]);

            return 0;
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
