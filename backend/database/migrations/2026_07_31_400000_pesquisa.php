<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pesquisa (A2.3) — dá função real ao Laboratório.
 *
 * O `Funcoes::CATALOGO` descreve o Laboratório como `'efeito' => 'nenhum'`, com a nota de que
 * *"o GDD diz duas palavras e nunca publica árvore de pesquisa, tecnologias, custo nem tempo"*.
 * Ou seja: **cada número desta fase seria invenção**, e o `BALANCEAMENTO.md` §8.1 os lista todos
 * como variáveis a decidir.
 *
 * Por isso `research_settings.ativo` nasce `false`, pela mesma razão da população (D-167): o mundo
 * não tem reset, e uma árvore com custo e duração de palpite mexeria na economia de um jogo no ar.
 * O que entra é a **estrutura**; os números se arbitram com evidência da trilha A2.S.
 *
 * ## Sem "Pontos de Pesquisa"
 *
 * §8.2 é explícito: pesquisa consome **recursos que já existem no jogo**. Uma moeda paralela criaria
 * uma segunda economia para balancear, e a fase existe para dar escolha, não para dobrar o trabalho.
 *
 * ## O Observatório NÃO entra
 *
 * Ele não existe no jogo, e criá-lo exige decisão de slot, arte e especificação próprias (§7.2). O
 * paralelismo sai do **nível do Laboratório**. Mas o mecanismo de vagas nasce extensível — ver
 * `Domain\Pesquisa\Vagas`, que soma contribuições de fontes; hoje há uma, amanhã pode haver duas,
 * sem refazer o modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_settings', function (Blueprint $table) {
            $table->id();

            /*
             * Enquanto `false`, ninguém inicia pesquisa. A árvore pode estar cadastrada e visível,
             * mas o motor recusa — é o que separa "a estrutura existe" de "os números estão
             * decididos".
             */
            $table->boolean('ativo')->default(false);

            // Vagas = base + (nível do Laboratório ÷ divisor), limitado ao teto. Tudo parâmetro.
            $table->unsignedTinyInteger('vagas_base')->default(1);
            $table->unsignedTinyInteger('vagas_por_niveis_de_laboratorio')->default(5);
            $table->unsignedTinyInteger('vagas_teto')->default(3);

            $table->timestamps();
        });

        DB::table('research_settings')->insert([
            'id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        /*
         * O catálogo. Os campos são exatamente os que a A2.3 lista no bloco "Backend" — nenhum
         * inventado, nenhum omitido.
         */
        Schema::create('technologies', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 60)->unique();
            $table->string('nome', 80);
            $table->string('descricao', 240);

            // As oito trilhas iniciais da fase. "Espacial" fica preparada conceitualmente e não
            // entra na primeira entrega — por isso não está na lista de valores aceitos ainda.
            $table->string('trilha', 20);

            /*
             * Pré-requisito, auto-referente — mesmo idioma de `mission_templates.requer_template_id`
             * (D-140). Uma tecnologia por pré-requisito basta para desenhar árvore; exigir duas
             * viraria grafo, e grafo é decisão de desenho que ninguém tomou.
             */
            $table->foreignId('requer_technology_id')->nullable()
                ->constrained('technologies')->nullOnDelete();

            $table->json('custo_json');
            $table->unsignedInteger('duracao_segundos');
            $table->unsignedTinyInteger('nivel_maximo')->default(1);
            $table->unsignedTinyInteger('laboratorio_minimo')->default(1);

            /*
             * Efeitos no MESMO vocabulário do `EfeitosDaEndurance` — `producao_bonus`,
             * `desconto_tributo`, `velocidade_veiculo`… em pontos-base, com os mesmos tetos
             * agregados. Criar um vocabulário paralelo faria duas fontes de bônus com regras
             * diferentes para a mesma coisa, e o teto de uma não conheceria a outra.
             */
            $table->json('efeitos_json')->nullable();

            $table->boolean('ativa')->default(true);

            /*
             * `versao`: a fase pede explicitamente. Serve para o operador mexer em custo ou efeito
             * sem ambiguidade sobre o que uma colônia pesquisou — a linha de `colony_technologies`
             * guarda a versão vigente na conclusão.
             */
            $table->unsignedInteger('versao')->default(1);

            $table->timestamps();

            $table->index(['trilha', 'ativa']);
        });

        Schema::create('colony_technologies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technology_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('nivel')->default(0);
            $table->string('status', 12)->default('pesquisando');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('finishes_at')->nullable();

            // A versão do catálogo no momento em que esta pesquisa começou. Ver `technologies.versao`.
            $table->unsignedInteger('versao')->default(1);

            $table->timestamps();

            /*
             * Uma linha por tecnologia por colônia: o NÍVEL sobe na mesma linha. Uma linha por nível
             * pesquisado faria a consulta de efeito ter de somar histórico, e o efeito é o do nível
             * atual — mesma lógica do requisito de operador na A2.2.
             */
            $table->unique(['colony_id', 'technology_id']);
            $table->index(['status', 'finishes_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colony_technologies');
        Schema::dropIfExists('technologies');
        Schema::dropIfExists('research_settings');
    }
};
