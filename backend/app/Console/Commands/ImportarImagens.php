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
     * ⚠️ **O que não está aqui, eu não sei o que é** — ou o jogo ainda não tem onde pendurar.
     * Chutar poria arte errada num prédio, e meses depois ninguém saberia por quê. Fica na
     * biblioteca, sem vínculo, e quem sabe o que quer escolhe no painel — **vendo a miniatura**.
     *
     * No D-72 as 28 restantes foram enfim olhadas uma a uma: **12 eram evidentes** e desceram para
     * a tabela; **7 têm duas leituras** e esperam a escolha do operador no painel (`torre-axiom`,
     * `aquifero-talassa`, `bastiao-vanguarda`, `estufa-lumen`, `centro-cerco-kraken`,
     * `terminal-aduaneiro-vetor`, `camara-escrow-prisma`); e **9 não têm lar no jogo** — as oito
     * seções da Endurance (a área Oeste já mostra o casco inteiro) e o `cargueiro-zenith`, que é o
     * Cargueiro Interplanetário de um Espaçoporto que ainda não existe.
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

        // ── A segunda leva (D-72): as 28 que sobraram, enfim olhadas uma a uma.
        //    O critério continua o mesmo — a IMAGEM tem de provar; duas destas cruzam de categoria
        //    (a pasta do artista não manda no jogo).

        // Os ministérios da Capital que a arte identifica sozinha.
        'bastiao-aegis' => 'capital:slot:5',      // escudo no frontão, torres, radares: a guerra
        'cofre-meridian' => 'capital:slot:4',     // cofre com estandartes de gráficos: as finanças
        'forum-concordia' => 'capital:slot:7',    // a balança da justiça holográfica: quem julga
        'terminal-atlas' => 'capital:slot:8',     // pátio de cargas com portões: os transportes

        // As especializações da colônia, pela FUNÇÃO que a arte mostra.
        'forja-titan' => 'oficina',                       // fundição — quem produz as Ligas
        'observatorio-kepler' => 'antena_de_comunicacao', // a parabólica gigante domina a cena
        'salao-aurum' => 'mercado_local',                 // o salão dourado de comércio
        'terminal-mercurio' => 'plataforma_de_pouso',     // a plataforma octogonal é o centro
        'torre-vulcan' => 'tanque_de_combustivel',        // cilindros deitados na base; sem arma no topo

        // As que cruzam de categoria: a pasta diz uma coisa, a imagem prova outra.
        'extratora-rubicon' => 'mina_local',       // sonda de perfuração — extração, não zona
        'doca-meridiana' => 'central_de_transportes',  // docas e guindastes — o hub de veículos
        'torre-trafego-zenite' => 'torre_de_vigia',    // radares: ver o ataque chegando (o aviso do D-70)

        // ── O lote de `structures.zip` (D-107): DIFERENTE dos dois anteriores, os nomes de
        //    arquivo JÁ SÃO canônicos (`deposito-local`, `reator-energia`) — vieram de uma lista
        //    mestra com o nome da estrutura ao lado do arquivo, não de fantasia. Mesmo assim, o
        //    vínculo saiu de CRUZAR o manifesto com `Vinculaveis::todas()`, não de aceitar o texto
        //    dele sozinho: boa parte das ~60 estruturas do lote não tem chave hoje (Mercado e
        //    Comércio, Espaçoporto, quase todos os "Destroços da Endurance", e metade das
        //    "Especializações da Colônia" como Estufa Bioluminescente/Torre Geotérmica/Complexo
        //    Metalúrgico/Observatório/Salão de Negociações) — o jogo ainda não tem onde pendurá-las.
        //
        //    ⚠️ MUITAS destas chaves JÁ TÊM uma imagem vinculada, vinda do D-68/D-72 (nomes de
        //    fantasia: `reator-helios`, `forja-titan`, etc.). O comando NUNCA troca um vínculo que
        //    já existe (`! ImageBinding::where('entity_key', $chave)->exists()`) — então estas
        //    entradas REGISTRAM a arte nova na biblioteca, mas NÃO tiram a arte antiga de cena. Quem
        //    quiser a arte nova no lugar da antiga troca no painel, vendo as duas miniaturas.

        // Capital: os 6 nomes de slot que o manifesto acerta em cheio (D-63/GDD §2.1). O slot 6
        // ("Pátio Logístico") NÃO é um slot próprio — é metade da área Leste (Mercado + Pátio,
        // D-65), que já tem arte (`mercado-aurora`); `patio-logistico.png` fica sem vínculo.
        'administracao-publica' => 'capital:slot:1',        // vazio até aqui — primeiro vínculo do slot 1
        'central-tributos' => 'capital:slot:2',
        'central-pesquisas-noticias' => 'capital:slot:3',
        'secretaria-financas' => 'capital:slot:4',
        'ministerio-seguranca-guerra' => 'capital:slot:5',
        'ministerio-reputacoes' => 'capital:slot:7',

        // Colônia Base: as 5 essenciais, pelo nome idêntico ao slug do jogo.
        'captacao-agua' => 'captacao_de_agua',
        'estrutura-sobrevivencia' => 'estrutura_de_sobrevivencia',
        'fazenda' => 'fazenda',
        'gerador-atmosfera' => 'gerador_de_atmosfera',
        'reator-energia' => 'reator_de_energia',

        // Progressão da colônia: as 13 batem com uma chave. `deposito-local` NÃO batia em D-107 —
        // `deposito_local` nasce fora de `Building::MVP` — mas `Vinculaveis::porCategoria()` já o
        // inclui à parte (a arte própria dele, D-105/106), e ninguém tinha atualizado esta lista
        // desde então: a imagem já estava na biblioteca (D-107), só sem vínculo. Fechado no D-143.
        'deposito-local' => 'deposito_local',
        'oficina' => 'oficina',                             // vazio até aqui
        'refinaria-quimica' => 'refinaria_quimica',         // vazio até aqui
        'laboratorio' => 'laboratorio',                     // vazio até aqui
        'antena-comunicacao' => 'antena_de_comunicacao',
        'torre-defesa' => 'torre_de_defesa',                // vazio até aqui
        'mercado-local' => 'mercado_local',
        'quartel' => 'quartel',                             // vazio até aqui
        'plataforma-pouso' => 'plataforma_de_pouso',
        'central-transportes' => 'central_de_transportes',
        'mina-local' => 'mina_local',
        'destilaria' => 'destilaria',                       // vazio até aqui
        'tanque-combustivel' => 'tanque_de_combustivel',

        // Especializações da colônia: só `bastiao` bate — as outras 7 (Estufa Bioluminescente,
        // Aquífero Profundo, Torre Geotérmica, Complexo Metalúrgico, Terminal de Cargas,
        // Observatório, Salão de Negociações) não são `building_type` nenhum hoje.
        'bastiao' => 'bastiao',

        // Logística e frota: 6 dos 7 veículos/unidades. `cargueiro-interplanetario` continua sem
        // lar — é o mesmo caso do `cargueiro-zenith` do D-72, arte à espera de um Espaçoporto que
        // ainda não existe como feature.
        'caminhao-carga' => 'caminhao_de_carga',
        'drone-exploracao' => 'drone_de_exploracao',
        'furgao-comercio' => 'furgao_de_comercio',
        'nave-planetaria' => 'nave_de_transporte_planetaria',
        'robo-minerador' => 'robo_minerador',
        'sentinela' => 'sentinela',

        // Espaçoporto: só a área da Capital (Sul) tem chave hoje. `terminal-aduaneiro` e
        // `torre-trafego-orbital` não têm — o Espaçoporto como feature própria não existe (D-72).
        'espacoporto' => 'capital:area:sul',

        // Destroços da Endurance: só o casco inteiro (área Oeste) tem chave. As 8 seções
        // individuais continuam sem lar (D-72) — e 6 delas (`anel-habitacional-endurance`,
        // `baia-criogenica-endurance`, `matriz-comunicacao-endurance`, `modulo-medico-endurance`,
        // `secao-acoplagem-endurance`, `silo-suprimentos-endurance`) nem chegaram a ser copiadas: o
        // nome de arquivo colide, letra por letra, com uma imagem JÁ existente na biblioteca desde
        // o D-72 (conteúdo diferente, mesmo nome) — decisão de sobrescrever ou não fica para o
        // usuário, e não foi tomada aqui.
        'casco-principal-endurance' => 'capital:area:oeste',

        // Zonas neutras e conflito: 11 das 13 — e é aqui que o lote fecha quase todo o buraco que
        // o D-72 apontou ("Muralha, Depósito, Refinaria de Campo e Cemitério da zona" sem
        // candidata). `fortim-defesa` e `centro-cerco` ficam sem vínculo: o manifesto os trata como
        // estruturas à parte, mas o jogo só tem `bastiao` (já reivindicado por "Bastião", acima) e
        // `abrigo_de_robos` (já reivindicado por "Abrigo de Robôs Mineradores", abaixo) — chutar
        // qual delas seria a arte poria a estrutura errada num prédio.
        'posto-comando-zona' => 'posto_de_comando',
        'estrutura-extracao' => 'estrutura_de_extracao',   // vazio até aqui
        'deposito-recursos' => 'deposito_de_zona_neutra',  // vazio até aqui — fecha o buraco do D-72
        'abrigo-robos-mineradores' => 'abrigo_de_robos',   // vazio até aqui
        'muralha-perimetro' => 'muralha_de_perimetro',     // vazio até aqui — fecha o buraco do D-72
        'torre-vigia' => 'torre_de_vigia',
        'refinaria-campo' => 'refinaria_de_campo',         // vazio até aqui — fecha o buraco do D-72
        'central-comunicacao-zona' => 'central_de_comunicacao',   // vazio até aqui
        'plataforma-pouso-zona' => 'plataforma_de_pouso_da_zona', // vazio até aqui
        'estacionamento-caminhoes' => 'estacionamento_da_zona',   // vazio até aqui
        'cemiterio-robos' => 'cemiterio_de_robos',         // vazio até aqui — fecha o buraco do D-72

        // As 8 seções do casco da Endurance (D-132, Loja de Peças). Conferidas visualmente, uma a
        // uma — não por nome de arquivo. `casco-principal-endurance` e `secao-comando-endurance`
        // NÃO estão aqui de propósito: são variantes visuais de arte já vinculada
        // (`casco-endurance.png`/`comando-endurance.png`), não seções novas.
        'anel-habitacional-endurance' => 'endurance:secao:anel_habitacional',
        'baia-criogenica-endurance' => 'endurance:secao:baia_criogenica',
        'comando-endurance' => 'endurance:secao:comando',
        'matriz-comunicacao-endurance' => 'endurance:secao:matriz_comunicacao',
        'modulo-medico-endurance' => 'endurance:secao:modulo_medico',
        'nucleo-propulsao-endurance' => 'endurance:secao:nucleo_propulsao',
        'secao-acoplagem-endurance' => 'endurance:secao:secao_acoplagem',
        'silo-suprimentos-endurance' => 'endurance:secao:silo_suprimentos',

        // ── A "Lista Mestra de Assets de Estruturas" (D-143): o usuário colou um manifesto novo.
        //    A maior parte dos ~70 nomes já batia, letra por letra, com o lote do D-107
        //    (`administracao-publica`, as 5 essenciais, a Progressão, a zona neutra, a frota, as
        //    8 seções da Endurance) — nenhuma entrada nova precisou nascer para esses; se os
        //    arquivos chegarem com o MESMO nome de antes, o `unique(category, filename)` já os
        //    reconhece, e um vínculo que já existe nunca é trocado (a mesma trava de sempre).
        //
        //    O que era genuinamente novo: quatro artes DEDICADAS às 4 áreas da Capital (D-63) —
        //    até aqui, cada área usava a arte de UM slot/seção dela emprestada (o Oeste usava
        //    `casco-endurance`, por exemplo); `capital:area:norte` nunca teve NENHUM candidato, em
        //    nenhum lote. As outras três áreas já tinham arte — estas entram como candidatas a
        //    mais, do mesmo jeito que `casco-principal-endurance` já tinha entrado no D-107 sem
        //    tirar `casco-endurance` de cena.
        'governo-central-norte' => 'capital:area:norte',      // a primeira arte que a área Norte já teve
        'mercado-central-leste' => 'capital:area:leste',
        'espacoporto-sul' => 'capital:area:sul',
        'destrocos-endurance-oeste' => 'capital:area:oeste',

        //    `secao-comando-endurance` também está no manifesto — de propósito, FICA DE FORA daqui,
        //    pela mesma razão que `casco-principal-endurance` não é uma das 8 seções: é variante
        //    visual do casco, não conteúdo novo (D-107 já tinha decidido isso para o nome idêntico).
        //
        //    O resto do manifesto continua sem lar, pelos MESMOS motivos já registrados no D-72/
        //    D-107: `patio-logistico` (a área Leste já é Mercado+Pátio, D-65); as 7 "Especializações
        //    da Colônia" que não são `building_type` (Estufa Bioluminescente, Aquífero Profundo,
        //    Torre Geotérmica, Complexo Metalúrgico, Terminal de Cargas, Observatório, Salão de
        //    Negociações); `cargueiro-interplanetario`, `torre-trafego-orbital`,
        //    `terminal-aduaneiro` (Espaçoporto não existe como feature); `mercado-central`,
        //    `doca-mercado`, `camara-escrow` (Mercado e Comércio não tem catálogo próprio de
        //    itens vinculáveis); `fortim-defesa`, `centro-cerco` (o jogo só tem `bastiao` e
        //    `abrigo_de_robos` para isso, os dois já reivindicados).
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
            $this->line('  '.count($porVincular).' imagens. Ou têm duas leituras possíveis e a escolha é do');
            $this->line('  operador, ou o jogo ainda não tem onde pendurá-las (as seções da Endurance,');
            $this->line('  o Cargueiro). Ficam na biblioteca — ver o D-72.');
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
