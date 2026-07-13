<?php

namespace App\Console\Commands;

use App\Domain\Media\Biblioteca;
use App\Domain\Media\NomesDeExibicao;
use App\Domain\Media\Vinculaveis;
use App\Models\ImageBinding;
use App\Models\MediaAsset;
use Illuminate\Console\Command;

/**
 * Registra na biblioteca as imagens que já estão em `/home/fertways/media` (docs/decisoes.md D-68).
 *
 *     artisan fertways:importar-imagens              # simula: diz o que faria
 *     artisan fertways:importar-imagens --aplicar    # registra e vincula
 *
 * **Idempotente.** Rodar de novo não duplica nada: o `unique(category, filename)` do banco é a trava,
 * e o comando pula o que já está registrado.
 *
 * ── Os vínculos propostos, e o que eles NÃO são ─────────────────────────────────────────────────
 *
 * As imagens vieram com nomes de fantasia (`reator-helios`, `estufa-aurora`) e o jogo conhece slugs
 * (`reator_de_energia`, `fazenda`). **Nenhuma associação é automática.** O que este comando faz é
 * propor os vínculos **evidentes pela arte** — um reator é um reator — e **deixar em branco tudo o
 * que for palpite**.
 *
 * ⚠️ O que ele NÃO faz é adivinhar. `nucleo-ares` pode ser o Gerador de Atmosfera ou a Estrutura de
 * Sobrevivência, e eu não sei qual; fica sem vínculo, e o operador escolhe no painel. Melhor um
 * hexágono honesto que um prédio errado.
 */
class ImportarImagens extends Command
{
    protected $signature = 'fertways:importar-imagens {--aplicar : registra; sem isto, só simula}';

    protected $description = 'Registra as imagens de /home/fertways/media e propõe os vínculos óbvios';

    /**
     * Os vínculos que a ARTE deixa claros. **Eu olhei as imagens**, uma a uma — não deduzi do nome.
     *
     * O critério é evidência visual: a imagem tem de mostrar, sem dúvida, a coisa que o jogo nomeia.
     * `estufa-aurora` são estufas com plantas dentro — é a Fazenda. `estacao-nereida` são tanques
     * azuis — é a Captação de Água. `nucleo-ares` tem chaminés e cilindros de gás sob uma cúpula —
     * é o Gerador de Atmosfera.
     *
     * ⚠️ **O que não está aqui, eu não sei o que é.** `extratora-rubicon`, `centro-cerco-kraken`,
     * `salao-aurum`, `forum-concordia` — a arte é bonita e ambígua, e o jogo não tem nada com esse
     * nome. Chutar poria arte errada num prédio, e meses depois ninguém saberia por quê. Elas ficam
     * na biblioteca, sem vínculo, e quem sabe o que quer escolhe no painel — **vendo a miniatura**.
     *
     * @var array<string,string> arquivo (sem `.png`) => chave da coisa no jogo
     */
    private const EVIDENTES = [
        // ── Logística e frota. Os nomes carregam o tipo, e a arte confirma.
        'furgao-orion' => 'furgao_de_comercio',
        'caminhao-colosso' => 'caminhao_de_carga',
        'nave-peregrina' => 'nave_de_transporte_planetaria',
        'drone-horizonte' => 'drone_de_exploracao',
        'sentinela-cygnus' => 'sentinela',
        'minerador-boreal' => 'robo_minerador',

        // ── As cinco essenciais da colônia (D-59). Identificadas OLHANDO a arte.
        'reator-helios' => 'reator_de_energia',              // é um reator, e o nome também diz
        'estufa-aurora' => 'fazenda',                        // estufas com plantas dentro
        'estacao-nereida' => 'captacao_de_agua',             // tanques azuis de água
        'habitat-pioneiro' => 'estrutura_de_sobrevivencia',  // módulos de habitação, painéis solares
        'nucleo-ares' => 'gerador_de_atmosfera',             // chaminés e cilindros de gás sob cúpula

        // ── Zona neutra. Só as duas que a arte prova.
        'posto-baluarte' => 'posto_de_comando',   // posto de comando com torre e muralhas
        'fortim-aegis-prime' => 'bastiao',        // fortaleza com torres de tiro

        // ── Capital: os que se nomeiam.
        'tesouro-solaris' => 'capital:slot:2',
        'instituto-gagarin' => 'capital:slot:3',

        // ── As áreas da Capital que o D-63 nomeia e a arte retrata.
        'espacoporto-gagarin' => 'capital:area:sul',
        'casco-endurance' => 'capital:area:oeste',
        'mercado-aurora' => 'capital:area:leste',
    ];

    public function handle(Biblioteca $biblioteca): int
    {
        if (! is_dir(Biblioteca::raiz())) {
            $this->error('Não achei '.Biblioteca::raiz().'. As imagens moram fora da árvore de deploy.');

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('aplicar');
        $novas = 0;
        $vinculadas = 0;
        $porVincular = [];

        foreach (array_keys(Biblioteca::CATEGORIAS) as $categoria) {
            $pasta = Biblioteca::raiz()."/{$categoria}";

            if (! is_dir($pasta)) {
                continue;
            }

            // Só as pequenas: cada uma pode ter uma irmã `_1024`, que é a versão grande.
            $arquivos = array_filter(
                scandir($pasta),
                fn ($f) => str_ends_with($f, '.png') && ! str_contains($f, '_1024'),
            );

            foreach ($arquivos as $arquivo) {
                $slug = basename($arquivo, '.png');
                $grande = "{$slug}_1024.png";
                $temGrande = is_file("{$pasta}/{$grande}");

                $ja = MediaAsset::where('category', $categoria)->where('filename', $arquivo)->first();

                if (! $ja) {
                    $novas++;

                    if ($aplicar) {
                        $ja = MediaAsset::create([
                            'category' => $categoria,
                            'filename' => $arquivo,
                            'filename_large' => $temGrande ? $grande : null,
                            'admin_id' => null,   // veio do import, não de um operador
                        ]);
                    }
                }

                $chave = self::EVIDENTES[$slug] ?? null;

                if ($chave === null) {
                    $porVincular[] = "{$categoria}/{$slug}";

                    continue;
                }

                if ($aplicar && $ja && ! ImageBinding::where('entity_key', $chave)->exists()) {
                    ImageBinding::create(['entity_key' => $chave, 'media_asset_id' => $ja->id]);
                    $vinculadas++;
                } elseif (! $aplicar) {
                    $vinculadas++;
                }
            }
        }

        $this->info(($aplicar ? 'Registradas' : 'Registraria')." {$novas} imagem(ns).");
        $this->info(($aplicar ? 'Vinculadas' : 'Vincularia')." {$vinculadas} à sua construção.");

        if ($vinculadas > 0) {
            $this->line('');
            $this->line('Vínculos EVIDENTES (a arte não deixa dúvida):');
            foreach (self::EVIDENTES as $arquivo => $chave) {
                $nome = Vinculaveis::todas()[$chave] ?? NomesDeExibicao::de($chave);
                $this->line("  {$arquivo}.png  →  {$nome}");
            }
        }

        if ($porVincular !== []) {
            $this->line('');
            $this->warn('SEM vínculo — escolha no painel (/central/admin/imagens):');
            $this->line('  '.count($porVincular).' imagens. Eu não sei o que elas são, e chutar poria');
            $this->line('  arte errada num prédio sem ninguém saber por quê. Ficam na biblioteca.');
            foreach ($porVincular as $p) {
                $this->line("    {$p}");
            }
        }

        if (! $aplicar) {
            $this->line('');
            $this->warn('Simulação. Rode com --aplicar.');
        }

        return self::SUCCESS;
    }
}
