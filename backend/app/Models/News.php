<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um comunicado do mural da Central de Pesquisas e Notícias (slot 3). Ver a migration.
 */
class News extends Model
{
    public $timestamps = false;

    protected $table = 'news';

    protected $fillable = ['title', 'body', 'kind', 'author', 'published_at'];

    protected $casts = [
        'published_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
