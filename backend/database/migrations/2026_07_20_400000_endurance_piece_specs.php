<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O catálogo da Loja de Peças da Endurance (D-132) sai do código (`EnduranceSpecs`) e vira
     * tabela — o usuário pediu um CRUD no painel para definir preço, marco, imagem e o efeito de
     * cada peça (D-133). A imagem continua vivendo em `image_bindings`, por SEÇÃO (já existia desde
     * o D-132) — as 4 camadas de uma seção compartilham a mesma arte; o painel de Peças da Endurance
     * só linka para `/admin/imagens`, não duplica o seletor.
     *
     * Esta migration cria a tabela E semeia as 32 linhas com os valores que já estavam em
     * `EnduranceSpecs::CAMADAS_SPEC` — ninguém perde o estado hoje em produção. Mesmo padrão de
     * migration-que-semeia-dado-inicial já usado no D-43 (correção dos índices de reputação).
     */
    public function up(): void
    {
        Schema::create('endurance_piece_specs', function (Blueprint $table) {
            $table->id();
            $table->string('peca_key', 60)->unique();
            $table->string('secao', 40);
            $table->string('secao_nome', 60);
            $table->string('camada', 20);
            $table->string('nome', 120);
            $table->unsignedTinyInteger('marco_minimo');
            $table->unsignedBigInteger('preco_micro');
            $table->unsignedInteger('desconto_tributo_bps');
            $table->boolean('unica')->default(false);
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        $secoes = [
            'anel_habitacional' => 'Anel Habitacional',
            'baia_criogenica' => 'Baía Criogênica',
            'comando' => 'Comando',
            'matriz_comunicacao' => 'Matriz de Comunicação',
            'modulo_medico' => 'Módulo Médico',
            'nucleo_propulsao' => 'Núcleo de Propulsão',
            'secao_acoplagem' => 'Seção de Acoplagem',
            'silo_suprimentos' => 'Silo de Suprimentos',
        ];

        $camadas = [
            'comum' => ['marco' => 10, 'preco' => 20, 'desconto' => 100, 'rotulo' => 'Fragmento'],
            'reputacao_1' => ['marco' => 35, 'preco' => 60, 'desconto' => 250, 'rotulo' => 'Relíquia'],
            'reputacao_2' => ['marco' => 75, 'preco' => 150, 'desconto' => 500, 'rotulo' => 'Artefato Raro'],
            'unica' => ['marco' => 100, 'preco' => 500, 'desconto' => 1000, 'rotulo' => 'Peça Única'],
        ];

        $agora = now();
        $linhas = [];

        foreach ($secoes as $secaoChave => $secaoNome) {
            foreach ($camadas as $camadaChave => $spec) {
                $linhas[] = [
                    'peca_key' => "{$secaoChave}:{$camadaChave}",
                    'secao' => $secaoChave,
                    'secao_nome' => $secaoNome,
                    'camada' => $camadaChave,
                    'nome' => "{$secaoNome} — {$spec['rotulo']}",
                    'marco_minimo' => $spec['marco'],
                    'preco_micro' => $spec['preco'] * 1_000_000,
                    'desconto_tributo_bps' => $spec['desconto'],
                    'unica' => $camadaChave === 'unica',
                    'admin_id' => null,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }
        }

        DB::table('endurance_piece_specs')->insert($linhas);
    }

    public function down(): void
    {
        Schema::dropIfExists('endurance_piece_specs');
    }
};
