<?php

namespace App\Domain\News;

use App\Exceptions\DomainRuleException;
use App\Models\News;

/**
 * Publica e remove comunicados do mural da Central de Pesquisas e Notícias (slot 3). Ver D-55, D-56.
 *
 * Extraído do `fertways:noticia` para o comando e o painel de administração compartilharem.
 */
class PublicarNoticia
{
    public function publicar(string $titulo, string $corpo, ?string $autor = null): News
    {
        $titulo = trim($titulo);
        $corpo = trim($corpo);

        if ($titulo === '' || $corpo === '') {
            throw new DomainRuleException('campos_obrigatorios', 'Título e corpo são obrigatórios.');
        }

        return News::create([
            'title' => $titulo,
            'body' => $corpo,
            'kind' => 'comunicado',
            'author' => ($autor !== null && trim($autor) !== '') ? trim($autor) : 'Administração Pública',
            'published_at' => now(),
        ]);
    }

    public function remover(int $id): bool
    {
        return News::whereKey($id)->delete() > 0;
    }
}
