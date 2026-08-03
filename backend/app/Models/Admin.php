<?php

namespace App\Models;

use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Um operador do jogo (equipe). Autentica pelo guard `admin` (sessão), no painel de administração.
 *
 * **Sem `HasApiTokens`** de propósito: o admin não fala com a API de colono, só com o painel Blade.
 * Isolamento total das contas de colono (`User`). Ver a migration e docs/decisoes.md D-56.
 *
 * **Dois papéis** (D-61), e a linha entre eles é o que **altera o estado do jogo**:
 *
 *   dono      tudo. Gere admins e **realoca colônias** — a ação mais difícil de desfazer do painel.
 *   operador  julga casos, publica notícias, distribui o Tesouro; nos jogadores, vê, suspende e
 *             corrige estado. **Não gere admins e não realoca.**
 *
 * Não há papel por área: são poucos, são a equipe, e permissão fina custaria mais código do que
 * resolve.
 */
class Admin extends Authenticatable
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory;

    public const DONO = 'dono';

    public const OPERADOR = 'operador';

    public const PAPEIS = [self::DONO, self::OPERADOR];

    protected $fillable = ['name', 'email', 'password', 'role', 'desativado_em'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'desativado_em' => 'datetime',
        ];
    }

    public function ehDono(): bool
    {
        return $this->role === self::DONO;
    }

    /** Desativado ≠ apagado: o rastro dele na auditoria continua apontando para alguém. */
    public function ativo(): bool
    {
        return $this->desativado_em === null;
    }
}
