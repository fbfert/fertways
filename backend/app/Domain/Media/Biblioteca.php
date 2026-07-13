<?php

namespace App\Domain\Media;

use App\Exceptions\DomainRuleException;
use App\Models\ImageBinding;
use App\Models\MediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * A biblioteca de imagens (docs/decisoes.md D-68).
 *
 * Os arquivos vivem em `/home/fertways/media/<categoria>/`, **fora do repositório e fora da árvore de
 * deploy** — ver a migration para o porquê (o `deploy.sh` aborta com arquivo não rastreado, e 52 MB
 * de PNG no git é para sempre). O banco guarda só o caminho.
 *
 * A URL pública sai de um symlink: `public_html/media` → `/home/fertways/media`.
 */
class Biblioteca
{
    /** A pasta de produção. NUNCA use esta constante para escrever: use `raiz()`. */
    public const RAIZ_PRODUCAO = '/home/fertways/media';

    /**
     * Onde os arquivos moram. Fora de tudo o que o deploy toca.
     *
     * ⚠️ **Isto era uma constante, e o meu próprio teste apagou uma imagem de PRODUÇÃO com ela.** O
     * `ImagensTest` exercita o botão de apagar; o `apagar()` chama `unlink()` no caminho real; e o
     * arquivo — `reator-helios.png`, a arte do Reator de Energia — sumiu do disco. O teste passou
     * verde. Ninguém saberia, exceto pelo prédio que voltou a ser hexágono.
     *
     * Agora a raiz vem da config, o `phpunit.xml` a aponta para uma pasta temporária, e a guarda
     * abaixo **recusa-se a rodar** se um teste ainda assim mirar a pasta de verdade. É a família de
     * trava do D-27 (o `migrate:fresh` que apagou o banco do jogo): a defesa tem de estar no código,
     * e não na disciplina de quem escreve o teste.
     */
    public static function raiz(): string
    {
        $raiz = (string) config('fertways.media_raiz', self::RAIZ_PRODUCAO);

        if (app()->runningUnitTests() && $raiz === self::RAIZ_PRODUCAO) {
            throw new \RuntimeException(
                'Um teste está apontando a biblioteca de imagens para a pasta de PRODUÇÃO. '
                .'Isto já apagou um arquivo de verdade uma vez. Ver `phpunit.xml` e config/fertways.php.',
            );
        }

        return $raiz;
    }

    /** Como o navegador os alcança. O symlink faz o resto. */
    public const URL = '/media';

    /**
     * As categorias. **As oito do zip, mais `mapas`, que nasce vazia** (decisão do usuário).
     *
     * Fixas de propósito: uma categoria criada à mão e escrita errado vira uma pasta órfã que
     * ninguém acha depois.
     */
    public const CATEGORIAS = [
        'capital' => 'Capital',
        'colonia-base' => 'Colônia — base',
        'especializacoes-da-colonia' => 'Colônia — especializações',
        'zonas-neutras-e-conflito' => 'Zonas neutras e conflito',
        'logistica-e-frota' => 'Logística e frota',
        'mercado-e-comercio' => 'Mercado e comércio',
        'espacoporto' => 'Espaçoporto',
        'destrocos-da-endurance' => 'Destroços da Endurance',
        'mapas' => 'Mapas',
    ];

    /** 8 MB. Um PNG de 1024×1024 pesa ~1,2 MB; o teto dá folga sem convidar a subir um cartaz. */
    public const TETO_BYTES = 8 * 1024 * 1024;

    /** A URL pública de uma imagem. */
    public static function url(MediaAsset $a, bool $grande = false): string
    {
        $arquivo = $grande ? ($a->filename_large ?? $a->filename) : $a->filename;

        return self::URL."/{$a->category}/{$arquivo}";
    }

    /**
     * Recebe um PNG do painel.
     *
     * ⚠️ **O nome do arquivo é reescrito, e isso não é preciosismo.** Um nome vindo do navegador pode
     * conter `../`, espaços, acentos, ou colidir com um arquivo que já existe. Ele vira um slug do
     * nome original mais um sufixo curto — assim dois envios de "torre.png" não se sobrescrevem, e
     * apagar-e-reenviar não esbarra no cache do navegador (a URL muda).
     */
    public function enviar(string $categoria, UploadedFile $arquivo, ?int $adminId): MediaAsset
    {
        if (! array_key_exists($categoria, self::CATEGORIAS)) {
            throw new DomainRuleException('categoria_invalida', "Categoria desconhecida: {$categoria}.");
        }

        if ($arquivo->getSize() > self::TETO_BYTES) {
            throw new DomainRuleException(
                'imagem_grande_demais',
                'A imagem passa de '.(self::TETO_BYTES / 1024 / 1024).' MB.',
            );
        }

        // Só PNG. O jogo desenha sprites com fundo transparente, e JPEG não tem transparência —
        // um JPEG viraria um quadrado branco em cima do hexágono.
        if (strtolower($arquivo->getClientOriginalExtension()) !== 'png'
            || $arquivo->getMimeType() !== 'image/png') {
            throw new DomainRuleException(
                'formato_invalido',
                'Só PNG. O sprite precisa de fundo transparente, e JPEG não tem.',
            );
        }

        $base = Str::slug(pathinfo($arquivo->getClientOriginalName(), PATHINFO_FILENAME));
        $nome = ($base !== '' ? $base : 'imagem').'-'.Str::lower(Str::random(6)).'.png';

        $pasta = self::raiz()."/{$categoria}";

        if (! is_dir($pasta)) {
            mkdir($pasta, 0755, true);
        }

        $arquivo->move($pasta, $nome);

        return MediaAsset::create([
            'category' => $categoria,
            'filename' => $nome,
            'filename_large' => null,   // um upload avulso não traz a versão grande.
            'admin_id' => $adminId,
        ]);
    }

    /**
     * Apaga da biblioteca — o arquivo e o registro.
     *
     * As construções que a usavam **voltam ao hexágono**: o `cascadeOnDelete` do vínculo cuida disso.
     * Quem chama tem de avisar antes quais são; ver o painel.
     *
     * @return list<string> as chaves das entidades que perderam a arte
     */
    public function apagar(MediaAsset $a): array
    {
        $orfas = ImageBinding::where('media_asset_id', $a->id)->pluck('entity_key')->all();

        foreach ([$a->filename, $a->filename_large] as $f) {
            if ($f === null) {
                continue;
            }

            $caminho = self::raiz()."/{$a->category}/{$f}";

            // `realpath` + prefixo: uma linha de banco adulterada não pode nos fazer apagar
            // /etc/passwd. O caminho tem de estar DENTRO da raiz da biblioteca.
            $real = realpath($caminho);

            if ($real !== false && str_starts_with($real, self::raiz().'/') && is_file($real)) {
                unlink($real);
            }
        }

        $a->delete();   // o cascade desfaz os vínculos

        return $orfas;
    }
}
