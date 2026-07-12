<?php

namespace App\Domain\Admin;

use App\Domain\Logistics\MapaFertways;
use App\Domain\Transport\Conservacao;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\TradeAgreement;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Move uma colônia para outra célula do mapa (D-61). **Só o dono.**
 *
 * É a ação mais difícil de desfazer do painel, e a que mais mexe com o mundo dos **outros**
 * jogadores — a distância é o eixo de toda a logística (§25.6), e mudá-la muda o frete, o tempo e a
 * energia de todo mundo que negocia com esta colônia.
 *
 * **Ela força.** O `artisan fertways:realocar-founders` **recusa** se houver veículo em rota, porque
 * a viagem guardou a distância no despacho e mover a colônia por baixo dela quebraria a conta. O
 * usuário decidiu o contrário para o painel: **realoca assim mesmo, e refaz as viagens** a partir da
 * posição nova. É god-mode consciente, e é para isso que existe a palavra REALOCAR e a auditoria.
 *
 * ---
 *
 * **Duas consequências que o assistente arbitrou (D-61), e um leitor futuro vai tropeçar nelas:**
 *
 *  - **A energia já gasta não é acertada.** O veículo pagou, no despacho, a energia da viagem inteira
 *    pela distância **antiga** (D-30). Se a colônia se muda para longe, a viagem refeita é mais cara
 *    do que o que ele pagou; se for para perto, mais barata. **Não há cobrança nem estorno.** Cobrar
 *    do colono uma energia que ele não escolheu gastar seria puni-lo por um ato do operador;
 *    estornar seria dar-lhe lucro pelo mesmo motivo. **O governo come a diferença.**
 *
 *  - **A viagem refeita recomeça do zero.** O trecho em curso é recalculado como se o veículo
 *    partisse **agora**, da posição nova. Não se tenta preservar a fração já percorrida: ela não
 *    existe em lugar nenhum do modelo — só há `departs_at` e `arrives_at` — e inventá-la seria fingir
 *    uma precisão que o jogo não tem.
 *
 * **O que NÃO se move:** as zonas neutras ocupadas ficam onde estão. Elas são território, não posse
 * portátil — a colônia só passa a estar mais perto ou mais longe delas.
 */
class RealocarColonia
{
    public function __construct(
        private readonly Auditoria $auditoria,
        private readonly Conservacao $conservacao,
    ) {}

    /** O que a realocação vai quebrar, para o painel avisar ANTES de o operador confirmar. */
    public function avisos(Colony $colony, int $x, int $y): array
    {
        $avisos = [];

        $emRota = $colony->vehicles()->where('status', 'em_rota')->count();

        if ($emRota > 0) {
            $avisos[] = "{$emRota} veículo(s) em rota terão a viagem refeita a partir da posição nova. "
                .'A energia já cobrada pela distância antiga não é acertada.';
        }

        /*
         * O prazo de um Acordo é um instante gravado (D-42: viagem + 12 h, calculado na aceitação).
         * Mudar a colônia de lugar não o recalcula — e se ela for para longe, pode não dar mais tempo
         * de cumprir. O painel avisa; a decisão é do operador, e fica na auditoria.
         */
        $acordos = TradeAgreement::query()
            ->where(fn ($q) => $q->where('colony_a_id', $colony->id)->orWhere('colony_b_id', $colony->id))
            ->whereIn('status', ['aceito', 'proposto'])
            ->count();

        if ($acordos > 0) {
            $avisos[] = "{$acordos} Acordo(s) de Troca em aberto têm prazo calculado pela distância ANTIGA. "
                .'Mudar para longe pode torná-los impossíveis de cumprir — e o calote conta contra quem não entregar.';
        }

        $zonas = $colony->id ? \App\Models\NeutralZone::where('owner_colony_id', $colony->id)->count() : 0;

        if ($zonas > 0) {
            $avisos[] = "{$zonas} zona(s) neutra(s) ocupada(s) NÃO se movem: a colônia só passará a estar "
                .'mais perto ou mais longe delas.';
        }

        return $avisos;
    }

    public function handle(Colony $colony, int $x, int $y, string $motivo): Colony
    {
        if (! MapaFertways::dentroDoMapa($x, $y)) {
            throw new DomainRuleException('fora_do_mapa', 'Esta célula não existe na grade.');
        }

        if (MapaFertways::ehCapital($x, $y)) {
            throw new DomainRuleException('celula_da_capital', 'A Capital fica ali. Escolha outra célula.');
        }

        if ((int) $colony->x === $x && (int) $colony->y === $y) {
            throw new DomainRuleException('mesma_celula', 'A colônia já está nesta célula.');
        }

        $ocupante = Colony::where('x', $x)->where('y', $y)->first();

        if ($ocupante) {
            throw new DomainRuleException(
                'celula_ocupada',
                "A célula ({$x}, {$y}) é da colônia \"{$ocupante->name}\".",
            );
        }

        if (trim($motivo) === '') {
            throw new DomainRuleException('motivo_obrigatorio', 'Toda realocação precisa de um motivo escrito.');
        }

        $antes = ['x' => (int) $colony->x, 'y' => (int) $colony->y];
        $avisos = $this->avisos($colony, $x, $y);

        DB::transaction(function () use ($colony, $x, $y) {
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();
            $colony->update(['x' => $x, 'y' => $y]);

            $this->refazerViagens($colony->fresh());
        });

        $this->auditoria->registrar(
            'colonia.realocar',
            "Realocou {$colony->name} de ({$antes['x']}, {$antes['y']}) para ({$x}, {$y}). Motivo: {$motivo}",
            "colony:{$colony->id}",
            $antes + ['avisos' => $avisos],
            ['x' => $x, 'y' => $y],
        );

        return $colony->fresh();
    }

    /**
     * Refaz cada viagem em curso a partir da posição nova.
     *
     * A distância é recalculada do **destino real** de cada veículo, e o trecho recomeça agora. Um
     * veículo que estava a 2 minutos de chegar pode, depois disto, ter uma hora pela frente — e o
     * contrário também. É a natureza da coisa: a colônia dele mudou de lugar.
     */
    private function refazerViagens(Colony $colony): void
    {
        $emRota = Vehicle::where('colony_id', $colony->id)->where('status', 'em_rota')->get();

        foreach ($emRota as $veiculo) {
            $distancia = $this->distanciaAoDestino($colony, $veiculo);

            if ($distancia === null) {
                continue;
            }

            $agora = now();

            $veiculo->forceFill([
                'distance_slots' => $distancia,
                'departs_at' => $agora,
                'arrives_at' => $agora->copy()->addSeconds(
                    $this->conservacao->segundosDoTrecho($veiculo, $distancia),
                ),
            ])->save();
        }
    }

    /**
     * A distância do trecho, já pela posição NOVA da colônia.
     *
     * **Ida e volta valem a mesma coisa**, e não é descuido: o veículo percorre o mesmo caminho nos
     * dois sentidos, e o que mudou de lugar foi só uma das duas pontas — a colônia. Quem está na
     * volta sente mais (vem para uma casa que se mudou), mas a conta é idêntica.
     *
     * Os três destinos são os que o `DespacharVeiculo` sabe produzir: a Capital, outra colônia, e uma
     * zona neutra. `null` desliga o recálculo daquele veículo — melhor deixá-lo com a rota antiga do
     * que inventar uma distância para um destino que sumiu.
     */
    private function distanciaAoDestino(Colony $colony, Vehicle $veiculo): ?int
    {
        return match ($veiculo->destination_type) {
            'mercado_central' => MapaFertways::ateCapital($colony->x, $colony->y),
            'colonia' => ($destino = Colony::find($veiculo->destination_id))
                ? MapaFertways::distancia($colony->x, $colony->y, $destino->x, $destino->y)
                : null,
            'zona_neutra' => ($zona = NeutralZone::find($veiculo->destination_id))
                ? MapaFertways::distancia($colony->x, $colony->y, $zona->x, $zona->y)
                : null,
            default => null,
        };
    }
}
