<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O upgrade de veículo passa a levar o tempo que a A2.7 prometeu (D-259).
 *
 * `transport_settings.upgrade_segundos_por_nivel` nasceu com a frase "o veículo fica indisponível
 * enquanto sobe", e ninguém o lia. Agora o veículo entra em `em_upgrade` e sai no tick, em `ready_at`
 * — a mesma coluna que a fábrica já usa para "fica pronto em".
 *
 * Só o ENUM muda, no mesmo molde do `2026_07_12_120000_ministerio_dos_transportes`.
 */
return new class extends Migration
{
    private const ANTES = ['ocioso', 'carregando', 'em_rota', 'descarregando', 'fabricando', 'estoque'];

    private const DEPOIS = [...self::ANTES, 'em_upgrade'];

    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->enum('status', self::DEPOIS)->default('ocioso')->change();
        });
    }

    public function down(): void
    {
        // Quem está no meio do serviço sai dele JÁ no nível novo: o custo foi pago, e desfazer a
        // migration não pode tirar do jogador o que ele comprou — só adiantar o relógio.
        app(\App\Domain\Transport\ConcluirUpgradesDeVeiculo::class)->handle(now()->addYears(100));

        Schema::table('vehicles', function (Blueprint $table) {
            $table->enum('status', self::ANTES)->default('ocioso')->change();
        });
    }
};
