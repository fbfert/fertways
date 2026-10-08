<?php

namespace App\Domain\Eventos;

use App\Models\GameEvent;
use App\Models\XpEntry;
use Illuminate\Support\Facades\DB;

/**
 * As condições compostas do gatilho `condicao` (D-253 — o "Depois" da A2.8).
 *
 * ## O catálogo é fechado, de propósito
 *
 * Uma condição é `métrica · operador · valor`, e o operador só escolhe a métrica de uma lista. Uma
 * expressão livre (SQL, fórmula) seria poder demais num painel e impossível de testar. Cada métrica
 * aqui é uma pergunta sobre o mundo que já se faz em outro lugar do jogo — nenhuma é inventada para
 * caber num evento.
 *
 * ## Composta, e só em um nível
 *
 * `todas` (E) ou `qualquer` (OU) sobre uma lista de regras. Sem aninhar: dois níveis de E/OU num
 * formulário já não se leem, e o operador que precisar de mais encadeia dois eventos.
 *
 * O valor é do operador; o jogo só compara.
 */
class CondicoesDoMundo
{
    public const METRICAS = [
        'colonias' => 'Colônias fundadas',
        'zonas_ocupadas' => 'Zonas neutras ocupadas',
        'combates' => 'Combates registrados (desde sempre)',
        'federacoes' => 'Federações',
        'colonias_ativas_7d' => 'Colônias que agiram nos últimos 7 dias',
    ];

    public const OPERADORES = ['>=', '<=', '>', '<', '='];

    /** @return array<string,int> o valor de cada métrica agora */
    public function medir(): array
    {
        return [
            'colonias' => DB::table('colonies')->count(),
            'zonas_ocupadas' => DB::table('neutral_zones')->whereNotNull('owner_colony_id')->count(),
            'combates' => DB::table('combats')->count(),
            'federacoes' => DB::table('federations')->count(),
            // `xp_entries`, e não o ledger: o ledger recebe produção de colônia abandonada (D-243).
            'colonias_ativas_7d' => XpEntry::where('created_at', '>=', now()->subDays(7))
                ->distinct()->count('colony_id'),
        ];
    }

    /**
     * As condições do evento valem agora?
     *
     * @param  array<string,int>|null  $medidas  passadas de fora para medir o mundo uma vez por passada
     */
    public function satisfeitas(GameEvent $evento, ?array $medidas = null): bool
    {
        $c = $evento->condicoes ?? [];
        $regras = $c['regras'] ?? [];

        if ($regras === []) {
            return false;   // condição vazia nunca dispara: um evento armado sem regra é engano.
        }

        $medidas ??= $this->medir();
        $resultados = array_map(fn ($r) => $this->regra($r, $medidas), $regras);

        return ($c['modo'] ?? 'todas') === 'qualquer'
            ? in_array(true, $resultados, true)
            : ! in_array(false, $resultados, true);
    }

    /**
     * Valida e normaliza o que veio do painel ou do `artisan`.
     *
     * @param  list<array{metrica:string,op:string,valor:int|string}>  $regras
     * @return array{modo:string,regras:list<array{metrica:string,op:string,valor:int}>}
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizar(string $modo, array $regras): array
    {
        if (! in_array($modo, ['todas', 'qualquer'], true)) {
            throw new \InvalidArgumentException("Modo desconhecido: «{$modo}» (use todas ou qualquer).");
        }

        $limpas = [];

        foreach ($regras as $r) {
            $metrica = (string) ($r['metrica'] ?? '');
            $op = (string) ($r['op'] ?? '');
            $valor = $r['valor'] ?? null;

            if (! array_key_exists($metrica, self::METRICAS)) {
                throw new \InvalidArgumentException("Métrica desconhecida: «{$metrica}».");
            }

            if (! in_array($op, self::OPERADORES, true)) {
                throw new \InvalidArgumentException("Operador desconhecido: «{$op}».");
            }

            if (! is_numeric($valor) || (int) $valor < 0) {
                throw new \InvalidArgumentException("Valor inválido para {$metrica}: «{$valor}».");
            }

            $limpas[] = ['metrica' => $metrica, 'op' => $op, 'valor' => (int) $valor];
        }

        if ($limpas === []) {
            throw new \InvalidArgumentException('Um gatilho por condição precisa de ao menos uma regra.');
        }

        return ['modo' => $modo, 'regras' => $limpas];
    }

    /** @param array<string,int> $medidas */
    private function regra(array $r, array $medidas): bool
    {
        $tem = $medidas[$r['metrica'] ?? ''] ?? null;

        if ($tem === null) {
            return false;   // métrica que saiu do catálogo não dispara nada.
        }

        $valor = (int) ($r['valor'] ?? 0);

        return match ($r['op'] ?? '') {
            '>=' => $tem >= $valor,
            '<=' => $tem <= $valor,
            '>' => $tem > $valor,
            '<' => $tem < $valor,
            '=' => $tem === $valor,
            default => false,
        };
    }
}
