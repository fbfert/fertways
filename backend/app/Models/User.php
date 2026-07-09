<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'nickname',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Os quatro índices de reputação (GDD §26) são colunas isoladas, nunca um agregado:
     * o GDD veda expressamente compensação cruzada entre eles. O MVP só movimenta
     * `confianca_comercial`; os outros três existem e ficam em zero.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'tutorial_completed_at' => 'datetime',
            'password' => 'hashed',
            'confianca_comercial' => 'integer',
            'conduta_social' => 'integer',
            'status_civico' => 'integer',
            'honra_militar_diplomatica' => 'integer',
        ];
    }

    public function colony(): HasOne
    {
        return $this->hasOne(Colony::class);
    }

    /** Destrava a subvenção das cinco essenciais até o nível 3 (GDD §24.7). */
    public function tutoriaConcluida(): bool
    {
        return $this->tutorial_completed_at !== null;
    }
}
