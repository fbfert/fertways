<?php

namespace App\Domain\Capital;

use App\Domain\Chat\ContaSistema;
use App\Domain\Chat\EnviarMensagem;
use App\Models\Colony;
use App\Models\Vehicle;
use Carbon\CarbonInterface;

/**
 * A Capital avisa, pelo rádio, quando um veículo fica parado no Pátio (D-91).
 *
 * Pedido do usuário: hoje o colono só descobre a tarifa do Pátio (D-65) se abrir a tela do
 * Mercado — um veículo podia ficar semanas ali, comendo Fert$ hora a hora, sem que nada
 * avisasse. Dois momentos avisam: **ao estacionar** (o relógio acabou de começar) e **a cada
 * 24h** que ele continuar lá (lembrete, não o spam de um aviso por hora cobrada).
 */
class AvisoDoPatio
{
    public function __construct(private readonly EnviarMensagem $chat) {}

    /** Chamado quando o veículo acabou de estacionar (D-65: entrega no depósito, fica no Pátio). */
    public function estacionou(Vehicle $v): void
    {
        $this->enviar($v, primeira: true);
    }

    /** Chamado pelo tick: só manda se já se passaram 24h do último aviso a este veículo. */
    public function lembrarSeDevido(Vehicle $v, CarbonInterface $agora): void
    {
        $desde = $v->patio_aviso_enviado_em ?? $v->parked_at;

        if (! $desde || $desde->diffInHours($agora) < 24) {
            return;
        }

        $this->enviar($v, primeira: false);
    }

    private function enviar(Vehicle $v, bool $primeira): void
    {
        $colonia = Colony::find($v->colony_id);
        $usuario = $colonia?->user;

        if (! $usuario) {
            return;
        }

        $tarifa = number_format(Patio::TARIFA_MICRO_HORA / 1_000_000, 3, ',', '.');
        $veiculo = $v->type === 'caminhao_de_carga' ? 'Caminhão de Carga' : 'Furgão de Comércio';

        $corpo = $primeira
            ? "Seu {$veiculo} chegou e ficou estacionado no meu Pátio. A tarifa é {$tarifa} Fert\$/hora "
                .'enquanto ele ficar aqui — despache-o de volta quando quiser, mesmo vazio.'
            : "Seu {$veiculo} continua parado no meu Pátio, cobrando {$tarifa} Fert\$/hora. Chame-o de "
                .'volta quando puder.';

        $this->chat->sistema(ContaSistema::capital(), $usuario, $corpo);

        $v->forceFill(['patio_aviso_enviado_em' => now()])->save();
    }
}
