<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Uma linha do log de auditoria (D-61). **Append-only.**
 *
 * O `Ledger` é append-only porque recurso não pode nascer sem história. Esta tabela existe porque o
 * **operador** era o único que podia criar valor sem deixar história — e um log que o próprio
 * operador pudesse editar ou apagar não seria auditoria, seria decoração.
 *
 * Por isso o modelo **impede** update e delete no código, além de a tabela não ter `updated_at`. As
 * duas travas são de propósito: quem contornar uma esbarra na outra.
 */
class AuditEntry extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = [
        'admin_id', 'admin_email', 'papel', 'acao', 'alvo', 'resumo', 'de', 'para', 'ip', 'agente', 'created_at',
    ];

    protected $casts = [
        'de' => 'array',
        'para' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('O log de auditoria é append-only: uma linha não se altera.');
        });

        static::deleting(function () {
            throw new RuntimeException('O log de auditoria é append-only: uma linha não se apaga.');
        });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /** Os campos que mudaram de fato, para a tela não mostrar dez linhas iguais. */
    public function mudancas(): array
    {
        $de = $this->de ?? [];
        $para = $this->para ?? [];

        $campos = array_unique([...array_keys($de), ...array_keys($para)]);
        $mudou = [];

        foreach ($campos as $campo) {
            $antes = $de[$campo] ?? null;
            $depois = $para[$campo] ?? null;

            if ($antes !== $depois) {
                $mudou[$campo] = ['de' => $antes, 'para' => $depois];
            }
        }

        return $mudou;
    }
}
