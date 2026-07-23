<?php

namespace App\Http\Controllers\Api;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\MapaFertways;
use App\Domain\Production\TaxasDeProducao;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\FoundingCell;
use App\Models\NeutralZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ColonyController extends Controller
{
    public function store(Request $request, CreateColony $criar): JsonResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:60'],
            // A célula escolhida pelo colono (D-51). O intervalo −50..50 é o mapa; se é
            // fundável de fato (founder populável ou periferia liberada, D-147), quem decide é
            // esta checagem logo abaixo, com erro de domínio legível.
            'x' => ['required', 'integer', 'between:-50,50'],
            'y' => ['required', 'integer', 'between:-50,50'],
        ]);

        $user = $request->user();

        // Uma colônia por jogador no MVP. A UNIQUE em colonies.user_id é a garantia real;
        // isto só devolve 422 em vez de deixar estourar a violação de constraint.
        if ($user->colony()->exists()) {
            return response()->json([
                'message' => 'Este colono já fundou uma colônia.',
            ], 422);
        }

        // A legitimidade da célula fica AQUI, no ponto de entrada de um jogador novo — não dentro
        // de `CreateColony::handle()` — pelo mesmo motivo que `RealocarColonia` (D-61) nunca
        // confere `podeFundar` pra mover uma colônia já existente: é uma regra sobre a CEREMÔNIA
        // de fundação, não uma invariante permanente de onde uma colônia pode existir. Mantê-la
        // fora do `handle()` também poupa toda ferramenta interna (testes, scaffolding) de ter
        // que liberar uma célula de periferia só para ter uma colônia de teste em algum lugar.
        // `$ehZonaNeutra` é consulta real, não `ZonasNeutras::ehZonaNeutra()` (D-148): desde que o
        // Dôno pode criar zona fora dos 4 distritos originais, só a tabela sabe a verdade inteira.
        $periferiaLiberada = FoundingCell::where('x', $dados['x'])->where('y', $dados['y'])->exists();
        $ehZonaNeutra = NeutralZone::where('x', $dados['x'])->where('y', $dados['y'])->exists();

        if (! MapaFertways::podeFundar($dados['x'], $dados['y'], $periferiaLiberada, $ehZonaNeutra)) {
            throw new DomainRuleException(
                'celula_invalida',
                'Esta célula não pode ser fundada: escolha um slot de founder livre ou uma célula de periferia já liberada.',
            );
        }

        $colony = $criar->handle($user, $dados['name'], $dados['x'], $dados['y']);

        return response()->json([
            'id' => $colony->id,
            'name' => $colony->name,
            'x' => $colony->x,
            'y' => $colony->y,
            'milestone' => $colony->milestone,
            'founded_at' => $colony->founded_at,
            'fert' => $colony->fert_micro / 1_000_000,
            'buildings' => $colony->buildings->map(fn ($b) => [
                'type' => $b->type,
                'level' => $b->level,
            ]),
            'resources' => $colony->resources->pluck('amount', 'resource_type'),
            'vehicles' => $colony->vehicles->map(fn ($v) => [
                'type' => $v->type,
                'level' => $v->level,
                'capacity' => $v->capacity,
                'status' => $v->status,
            ]),
        ], 201);
    }

    public function show(Request $request, TaxasDeProducao $taxas): JsonResponse
    {
        $colony = $request->user()->colony()->with(['buildings', 'resources', 'vehicles'])->first();

        if (! $colony) {
            return response()->json(['message' => 'Nenhuma colônia fundada.'], 404);
        }

        return response()->json([
            'id' => $colony->id,
            'name' => $colony->name,
            // Sem as coordenadas próprias a tela não tem de onde medir distância até ninguém.
            'x' => $colony->x,
            'y' => $colony->y,
            'fert' => $colony->fert_micro / 1_000_000,
            'last_tick_at' => $colony->last_tick_at,
            'buildings' => $colony->buildings->map(fn ($b) => ['type' => $b->type, 'level' => $b->level]),
            'resources' => $colony->resources->pluck('amount', 'resource_type'),
            // Taxa nominal de produção/consumo por hora (D-153) — não é o que o tick vai creditar
            // de fato (isso depende do insumo disponível no momento), é a capacidade plena.
            'taxas_hora' => $taxas->porRecurso($colony),
            // O Marco do §03/§05 (D-75): número, título publicado, e quanto falta para o próximo.
            'marco' => $this->marco($colony),
        ]);
    }

    /**
     * Diretório de colônias: o destino possível de um despacho.
     *
     * `POST /vehicles/{vehicle}/dispatch` aceita `destination_type = colonia` desde a fatia de
     * logística, mas exige a **chave primária** da colônia de destino — e não havia como um
     * jogador descobrir o `id` de ninguém. A UI só oferecia o Mercado Central por causa disso.
     *
     * O GDD é **omisso** sobre um diretório (nunca usa a palavra, e o §24.2 só garante que o slot
     * alheio é clicável no mapa, mostrando avatar e nickname). Listar todas as colônias, sem névoa
     * de guerra, é arbitragem do usuário — ver docs/decisoes.md D-37. A consequência assumida é
     * que o Drone de Exploração (§21) deixa de ter colônias a revelar; restam-lhe as zonas neutras.
     */
    public function index(Request $request): JsonResponse
    {
        $minha = $request->user()->colony()->first();

        // Sem colônia não há de onde medir distância, e não há veículo para despachar. Mesmo 404
        // de `show()`: a resposta honesta é "você ainda não fundou", não uma lista sem referencial.
        if (! $minha) {
            return response()->json(['message' => 'Nenhuma colônia fundada.'], 404);
        }

        $colonias = Colony::query()
            // A própria colônia fica de fora: `despachar` rejeita `destino_igual_origem`, e quem
            // a quiser já a tem inteira em `GET /colony`.
            ->whereKeyNot($minha->id)
            // `with` + `withSum` evitam o N+1: três consultas, não uma por colônia.
            ->with('user:id,nickname')
            ->withSum('buildings', 'level')
            ->get()
            ->map(fn (Colony $c) => [
                'id' => $c->id,
                // O card de informações do colono (D-81) é chaveado por USER, não por colônia.
                'user_id' => $c->user_id,
                'name' => $c->name,
                'nickname' => $c->user->nickname,
                'x' => $c->x,
                'y' => $c->y,
                'distance' => MapaFertways::distancia($minha->x, $minha->y, $c->x, $c->y),

                /*
                 * Soma dos níveis das construções — um sinal de porte, arbitrado (D-38).
                 *
                 * **Não é o "Marco" do GDD.** O GDD nomeia marcos (1 Sobrevivente, 5 Colono, 10
                 * Pioneiro… 100 Lenda de Fertways) mas nunca publica como o número se calcula, e
                 * `colonies.milestone` é uma string congelada em `colonizacao_inicial` desde a
                 * fundação. Chamar este campo de `level` convidaria a confundir os dois; o nome
                 * diz o que ele é. Quando o Marco existir de verdade, será um campo à parte.
                 */
                'building_levels_sum' => (int) $c->buildings_sum_level,
            ])
            // Vizinho primeiro: é a ordem em que o jogador decide para onde despachar (§25.6, a
            // posição no mapa importa). `id` desempata para a lista não dançar entre chamadas.
            ->sortBy(fn (array $c) => [$c['distance'], $c['id']])
            ->values();

        // Recursos, saldo e frota alheios ficam de fora: o §13 fala em "relatórios privados" sem
        // enumerar o que é público, e o diretório existe para escolher destino, não para espionar.
        //
        // `side`, `capital` e `me` são o que a tela do mapa precisa para desenhar. Vêm daqui, e
        // não de constantes no frontend, porque a geometria vai mudar (D-51: lado 101, Capital em
        // (0,0), coordenadas com sinal) e um número copiado no React sobreviveria à mudança
        // mentindo. Campos aditivos: quem já lia `colonies` não quebra.
        //
        // Os dois raios entram pelo mesmo motivo, desde o D-64: agora que o mapa desenha as
        // células de verdade, ele sombreia o disco de founders e o anel livre — e a fronteira de
        // uma faixa é a distância **exata** (`MapaFertways::faixaDe`), não a arredondada do frete.
        return response()->json([
            'side' => MapaFertways::LADO,
            'capital' => ['x' => MapaFertways::CAPITAL_X, 'y' => MapaFertways::CAPITAL_Y],
            'raio_founder' => MapaFertways::RAIO_FOUNDER,
            'raio_anel' => MapaFertways::RAIO_ANEL,
            'me' => ['id' => $minha->id, 'name' => $minha->name, 'x' => $minha->x, 'y' => $minha->y],
            'colonies' => $colonias,
        ]);
    }

    /**
     * O mapa para o seletor de fundação (D-51).
     *
     * A tela de fundação precisa mostrar onde dá para fundar **antes** de o colono ter colônia —
     * por isso, ao contrário de `index`, este endpoint não exige uma. Devolve a geometria, os 48
     * slots de founder (marcando reservado e ocupado), as células de periferia que o admin liberou
     * (D-147 — desde então a periferia não é mais "qualquer lugar") e as células já tomadas, para
     * o mapa clicável oferecer só o que é fundável: slot de founder populável livre ou célula de
     * periferia liberada e livre. A regra de fundabilidade fica em `MapaFertways::podeFundar`,
     * conferida de novo no servidor quando o `POST /colony` chega; o mapa aqui é só para a UI não
     * oferecer o impossível.
     *
     * Expõe apenas as coordenadas ocupadas, sem nome nem dono: o seletor precisa saber o que está
     * livre, não quem mora onde. (O diretório do D-37, esse sim, mostra nomes — mas só a quem já
     * fundou.)
     */
    public function map(Request $request): JsonResponse
    {
        $ocupadas = Colony::query()->get(['x', 'y'])
            ->map(fn (Colony $c) => ['x' => $c->x, 'y' => $c->y])
            ->values();

        $chaveOcupada = $ocupadas->mapWithKeys(fn (array $c) => ["{$c['x']}:{$c['y']}" => true]);

        $slots = collect(MapaFertways::slotsFounder())->map(fn (array $s) => [
            'x' => $s['x'],
            'y' => $s['y'],
            'reservado' => $s['reservado'],
            'ocupado' => $chaveOcupada->has("{$s['x']}:{$s['y']}"),
        ])->values();

        return response()->json([
            'side' => MapaFertways::LADO,
            'raio' => MapaFertways::RAIO,
            'capital' => ['x' => MapaFertways::CAPITAL_X, 'y' => MapaFertways::CAPITAL_Y],
            'raio_founder' => MapaFertways::RAIO_FOUNDER,
            'raio_anel' => MapaFertways::RAIO_ANEL,
            'founder_slots' => $slots,
            'periferia_liberada' => FoundingCell::query()->get(['x', 'y'])->values(),
            'colonias' => $ocupadas,
        ]);
    }

    /** @return array{numero: int, titulo: string, xp: int, xp_do_proximo: int|null} */
    private function marco(\App\Models\Colony $colony): array
    {
        $xp = (int) $colony->xp;
        $numero = \App\Domain\Marco\Curva::marco($xp);

        return [
            'numero' => $numero,
            'titulo' => \App\Domain\Marco\Curva::titulo($numero),
            'xp' => $xp,
            // No 100 não há próximo: a Lenda é o teto, e a tela não deve prometer um 101.
            'xp_do_proximo' => $numero >= 100 ? null : \App\Domain\Marco\Curva::xpDoMarco($numero + 1),
        ];
    }
}
