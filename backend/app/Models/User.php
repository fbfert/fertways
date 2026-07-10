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
     * Os quatro índices de reputação (GDD §26.2) são colunas isoladas, nunca um agregado: o §26.9
     * veda expressamente compensação cruzada entre eles, e não existe "reputação geral" (D-48).
     *
     * Todos nascem em 500, no meio da escala de 0 a 1000 (D-43, D-49). O Acordo de Troca move a
     * Confiança Comercial sozinho; os outros três só se movem por condenação no Ministério, e os que
     * dependem de chat, tratados e alianças ficam parados até esses sistemas existirem (D-44).
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
            // O cargo de conciliador (§9.3) e o que o §26.7 conta contra ele.
            'conciliador_desde' => 'datetime',
            'conciliador_suspenso_em' => 'datetime',
            'salario_pago_em' => 'datetime',
            'reversoes' => 'integer',
        ];
    }

    /** Nomeado, e não suspenso. É quem a triagem do §9.2 pode encarregar de um caso. */
    public function conciliadorAtivo(): bool
    {
        return $this->conciliador_desde !== null && $this->conciliador_suspenso_em === null;
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
