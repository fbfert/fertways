<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de Eventos — MVP (A2.8).
 *
 * ## O objetivo, que é do roadmap
 *
 * *"Dar ao Dono capacidade de criar emoção sem precisar alterar código para cada evento."* Hoje
 * qualquer acontecimento de mundo — uma tempestade que derruba a extração, uma safra farta — exigiria
 * um `if` novo no tick. Aqui vira **uma linha de tabela**.
 *
 * ## ⚠️ O evento NUNCA escreve no ledger
 *
 * Exigência literal do roadmap, e a razão é de arquitetura: o ledger é o registro do que
 * **aconteceu**, e um evento não faz nada acontecer — ele muda a **taxa**. Quem credita continua
 * sendo o tick, e um único lançamento por fato econômico continua valendo. Um evento que lançasse no
 * ledger criaria receita do nada e faria o D-163 (a telemetria que deriva do ledger) passar a mentir.
 *
 * ## ⚠️ Reconstruível no passado — e por isso o cancelamento não apaga
 *
 * *"O modificador precisa ser reconstruível no passado, para que 'Desde sua última visita' consiga
 * explicar por que a produção caiu."*
 *
 * Cancelar **encerra o futuro e preserva o passado**: `cancelado_em` marca o instante, e o efeito
 * vigente até ali continua calculável. Apagar a linha faria o resumo de retorno dizer que a produção
 * caiu sem motivo — e um jogo que não consegue explicar a própria economia perde a confiança do
 * jogador de um jeito que não se recupera.
 *
 * ## Só dois modificadores, e preço fica de fora
 *
 * **Produção** e **consumo**. Preço já tem dono: `price_interventions` existe desde o D-35, e o
 * roadmap é explícito — *"o motor não a absorve nem a duplica nesta versão"*. Duas verdades sobre o
 * preço seria a pior herança possível deste motor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_events', function (Blueprint $table) {
            $table->id();

            // ── identidade
            $table->string('slug')->unique();
            $table->string('nome');
            $table->text('descricao')->nullable();

            /*
             * O que o jogador lê quando o evento é anunciado. Separada da `descricao`, que é do
             * operador: a voz do mundo e a nota de bastidor nunca devem ser o mesmo texto, ou uma
             * das duas acaba vazando para o lado errado.
             */
            $table->text('mensagem_publica')->nullable();
            $table->text('notas_internas')->nullable();

            // ── janela
            /*
             * ⚠️ `dateTime`, e não `timestamp`. O MariaDB recusa a criação da tabela com DUAS colunas
             * `timestamp NOT NULL`: só a primeira ganha o default implícito, e a segunda recebe
             * `0000-00-00`, que o modo estrito rejeita. O SQLite dos testes teria deixado passar —
             * foi o MariaDB do dev que pegou, antes da produção.
             */
            $table->dateTime('comeca_em');
            $table->dateTime('termina_em');

            /*
             * `rascunho` não vale nada no mundo; `ativo` vale dentro da janela; `cancelado` parou de
             * valer em `cancelado_em` e **continua valendo para trás** — ver o docblock.
             */
            $table->enum('status', ['rascunho', 'ativo', 'cancelado'])->default('rascunho');
            $table->dateTime('cancelado_em')->nullable();

            /*
             * `anunciado` mostra nome e mensagem; `parcial` diz que ALGO mexe na produção sem dizer o
             * quê; `secreto` não aparece para ninguém.
             *
             * ⚠️ Visibilidade governa só a TELA. A auditoria e a telemetria registram os três iguais:
             * um evento secreto que também fosse invisível ao operador seria indistinguível de um bug.
             */
            $table->enum('visibilidade', ['anunciado', 'parcial', 'secreto'])->default('anunciado');

            /*
             * `mundo` atinge todas as colônias. `colonia` atinge uma só — é o que permite testar um
             * evento em campo antes de soltá-lo no mundo, que é o "dry-run" que a §Segurança pede na
             * forma mais honesta possível: rodando de verdade, em escala de um.
             */
            $table->enum('escopo', ['mundo', 'colonia'])->default('mundo');
            $table->foreignId('colony_id')->nullable()->constrained()->nullOnDelete();

            /*
             * O gatilho. Só `janela` nesta versão — o evento vale entre as duas datas. Os gatilhos
             * por condição (estoque, guerra, marco) são das versões seguintes, e o campo existe para
             * que acrescentá-los não exija migração de dados.
             */
            $table->string('gatilho')->default('janela');

            // ── o que ele faz
            /** `producao` ou `consumo`. Preço fica de fora — ver o docblock. */
            $table->enum('modificador', ['producao', 'consumo']);

            /**
             * Em pontos-base sobre a taxa: 500 = +5%, -2000 = −20%. Assinado de propósito, e a
             * direção vem do SINAL — foi a lição do D-164, quando eu li um ledger de valores
             * absolutos e concluí a regra errada.
             */
            $table->integer('efeito_bps');

            /** `null` = todos os recursos. Preenchido = só aquele. */
            $table->string('resource_type')->nullable();

            // ── o que ele dá, e o que ele pede
            $table->json('recompensas')->nullable();
            $table->json('missoes')->nullable();

            /*
             * `segredo` do MVP: o evento existe e opera, e nem o nome aparece em lugar nenhum do
             * jogo. Distinto de `visibilidade = secreto` por ser afirmação separada — um operador que
             * queira segredo tem de dizê-lo duas vezes, e isso é de propósito.
             */
            $table->boolean('segredo')->default(false);

            /** Versão do evento, para o operador iterar sobre o mesmo slug sem perder o histórico. */
            $table->unsignedInteger('versao')->default(1);

            // ── auditoria (§Segurança)
            $table->string('criado_por')->nullable();
            $table->timestamps();

            /*
             * O índice que o tick usa: ele pergunta "o que estava valendo entre estas duas datas?" a
             * cada colônia, a cada minuto. Sem isto seria varredura de tabela no caminho mais quente
             * do jogo.
             */
            $table->index(['status', 'comeca_em', 'termina_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_events');
    }
};
