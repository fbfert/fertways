<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Um operador do jogo (equipe). Autentica pelo guard `admin` (sessão), no painel de administração.
 *
 * **Sem `HasApiTokens`** de propósito: o admin não fala com a API de colono, só com o painel Blade.
 * Isolamento total das contas de colono (`User`). Ver a migration e docs/decisoes.md D-56.
 */
class Admin extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\AdminFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
