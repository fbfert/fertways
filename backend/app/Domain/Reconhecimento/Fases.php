<?php

namespace App\Domain\Reconhecimento;

use App\Models\MissaoDeReconhecimento;

/** Os nomes que o colono lê para cada fase (aditivo v3.4 §2.2, §2.3). */
final class Fases
{
    public const NOMES = [
        'triangulacao' => 'Triangulação pública',
        'preparacao' => 'Preparação da sonda',
        'voo' => 'Voo e leitura',
        'validacao' => 'Validação',
        'leitura_orbital' => 'Leitura orbital',
        'mapeamento' => 'Mapeamento de superfície',
        'janela_orbital' => 'Janela orbital lunar',
        'concluida' => 'Concluída',
    ];

    public const ANOMALIAS = [
        'gravitacional' => 'gravitacional',
        'energetica' => 'energética',
        'espectral' => 'espectral',
    ];

    public static function nome(string $fase): string
    {
        return self::NOMES[$fase] ?? $fase;
    }

    /** Uma linha do boletim: o alvo só aparece pelo nome quando já foi revelado. */
    public static function rotulo(MissaoDeReconhecimento $m): string
    {
        $alvo = $m->tipo === MissaoDeReconhecimento::LUNAR
            ? 'lua em '.$m->setor
            : 'anomalia '.(self::ANOMALIAS[$m->anomalia] ?? $m->anomalia).' em '.$m->setor;

        $progresso = in_array($m->fase, Gagarin::COM_META, true)
            ? " ({$m->tarefas} de {$m->meta_de_tarefas} tarefas)"
            : '';

        return self::nome($m->fase).", {$alvo}{$progresso}";
    }
}
