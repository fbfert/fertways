<?php

namespace App\Console\Commands;

use App\Models\News;
use Illuminate\Console\Command;

/**
 * Central de Pesquisas e Notícias (slot 3): publica e administra o mural de comunicados oficiais.
 *
 * **Por que artisan e não uma rota.** O §02: o Governo é "operado pela equipe". Enquanto o Gagarin
 * (auto-boletins) não ativa — 50 jogadores ou 45 dias — e sem formato de boletim publicado, o mural
 * é editorial e escrito à mão pela equipe (D-44, mesmo molde do `fertways:conciliador`). As notícias
 * são globais (do servidor). Ver docs/decisoes.md.
 *
 *   artisan fertways:noticia --publicar --titulo="Abertura do servidor" --corpo="Bem-vindos a Fertways."
 *   artisan fertways:noticia --publicar --titulo="..." --corpo="..." --autor="Repórter K-14"
 *   artisan fertways:noticia --listar
 *   artisan fertways:noticia --remover=3
 */
class Noticia extends Command
{
    protected $signature = 'fertways:noticia
        {--publicar}
        {--titulo= : título do comunicado}
        {--corpo= : texto do comunicado}
        {--autor=Administração Pública : quem assina}
        {--listar}
        {--remover= : id da notícia a remover}';

    protected $description = 'Publica comunicados no mural da Central de Pesquisas e Notícias';

    public function handle(): int
    {
        if ($this->option('listar')) {
            return $this->listar();
        }

        if ($this->option('remover')) {
            return $this->remover((int) $this->option('remover'));
        }

        if ($this->option('publicar')) {
            return $this->publicar();
        }

        $this->error('Use --publicar, --listar ou --remover=<id>.');

        return self::FAILURE;
    }

    private function publicar(): int
    {
        $titulo = trim((string) $this->option('titulo'));
        $corpo = trim((string) $this->option('corpo'));

        if ($titulo === '' || $corpo === '') {
            $this->error('--titulo e --corpo são obrigatórios.');

            return self::FAILURE;
        }

        $noticia = News::create([
            'title' => $titulo,
            'body' => $corpo,
            'kind' => 'comunicado',
            'author' => (string) $this->option('autor'),
            'published_at' => now(),
        ]);

        $this->info("Comunicado #{$noticia->id} publicado: {$titulo}");

        return self::SUCCESS;
    }

    private function remover(int $id): int
    {
        $n = News::whereKey($id)->delete();

        $this->info($n > 0 ? "Notícia #{$id} removida." : "Notícia #{$id} não encontrada.");

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $noticias = News::orderByDesc('published_at')->orderByDesc('id')->limit(30)->get();

        if ($noticias->isEmpty()) {
            $this->line('Mural vazio.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Publicado', 'Autor', 'Título'],
            $noticias->map(fn (News $n) => [
                $n->id,
                $n->published_at->format('Y-m-d H:i'),
                $n->author,
                $n->title,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
