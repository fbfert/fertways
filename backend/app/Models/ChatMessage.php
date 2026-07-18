<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma mensagem do §10 (D-77). Imutável na prática: chat não se edita, e a purga é por idade. */
class ChatMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'channel', 'recipient_user_id', 'x', 'y', 'federation_id', 'body', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
