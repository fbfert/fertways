<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O Silo (docs/decisoes.md D-107) é o Depósito Local (D-105/106) estendido: além de mostrar os
 * recursos, agora define quanto de cada um cabe protegido na colônia, por nível — o que exceder
 * fica exposto (a regra e o dado; o saque em si é uma entrega separada, já combinada com o
 * usuário).
 *
 * Mesmo molde do kit inicial (D-92): os valores de partida entram AQUI, numa migration one-time —
 * não num Seeder re-executável — porque esta tabela é editável pelo admin e não pode ser apagada
 * silenciosamente por um reseed futuro. `resource_types` ainda não está semeada quando esta
 * migration roda (seeders rodam depois de todas as migrations), por isso os 26 códigos vêm
 * escritos aqui, não de uma consulta — mesma razão que levou o kit inicial a fazer o mesmo.
 *
 * Padrão pedido pelo usuário: nível 1 a 10, 10.000 de cada recurso, igual em todos os níveis —
 * ele ajusta pelo próprio painel que esta entrega cria.
 */
return new class extends Migration
{
    private const RECURSOS = [
        'agua', 'aluminio', 'biocombustivel', 'bioenergia_curativa', 'biomassa', 'cobre',
        'componentes_eletronicos', 'compostos_quimicos', 'cristal_de_helio_3', 'energia',
        'estanho', 'ferro_vermelho', 'fungo_bioluminescente', 'gelo_de_metano', 'ligas_metalicas',
        'litio', 'metal_bruto', 'niobio_alienigena', 'ouro', 'oxigenio', 'plasma_fossilizado',
        'quartzo_piezoeletrico', 'resina_organica', 'silicio', 'tantalo', 'tungstenio',
    ];

    private const CAPACIDADE_PADRAO = 10_000;

    private const NIVEL_MAXIMO = 10;

    public function up(): void
    {
        Schema::create('silo_capacidades', function (Blueprint $table) {
            $table->id();
            $table->string('resource_type', 40);
            $table->unsignedTinyInteger('level');
            $table->unsignedBigInteger('capacidade');
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['resource_type', 'level']);
        });

        $agora = now();
        $linhas = [];

        foreach (self::RECURSOS as $recurso) {
            for ($nivel = 1; $nivel <= self::NIVEL_MAXIMO; $nivel++) {
                $linhas[] = [
                    'resource_type' => $recurso,
                    'level' => $nivel,
                    'capacidade' => self::CAPACIDADE_PADRAO,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }
        }

        DB::table('silo_capacidades')->insert($linhas);
    }

    public function down(): void
    {
        Schema::dropIfExists('silo_capacidades');
    }
};
