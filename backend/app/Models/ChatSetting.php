<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Os números do chat que são do OPERADOR (§10; D-77): o raio da vizinhança ("raio configurável",
 * §10.1 — configurável POR ALGUÉM, e esse alguém é o painel) e a lista de termos vedados, que o
 * §03 promete ser a mesma do nickname.
 */
class ChatSetting extends Model
{
    protected $fillable = ['vizinhanca_raio_slots', 'termos_vedados'];

    protected $casts = ['vizinhanca_raio_slots' => 'integer', 'termos_vedados' => 'array'];

    /** Relê depois de criar — a lição do WarSetting (D-70). */
    public static function singleton(): self
    {
        if ($existente = static::first()) {
            return $existente;
        }

        static::create([]);

        return static::firstOrFail();
    }

    /** @return list<string> minúsculos e sem espaços nas pontas, prontos para comparar */
    public function termos(): array
    {
        return array_values(array_filter(array_map(
            fn ($t) => mb_strtolower(trim((string) $t)),
            $this->termos_vedados ?? [],
        )));
    }
}
