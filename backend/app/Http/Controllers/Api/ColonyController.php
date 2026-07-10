<?php

namespace App\Http\Controllers\Api;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\MapaFertways;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ColonyController extends Controller
{
    public function store(Request $request, CreateColony $criar): JsonResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:60'],
        ]);

        $user = $request->user();

        // Uma colônia por jogador no MVP. A UNIQUE em colonies.user_id é a garantia real;
        // isto só devolve 422 em vez de deixar estourar a violação de constraint.
        if ($user->colony()->exists()) {
            return response()->json([
                'message' => 'Este colono já fundou uma colônia.',
            ], 422);
        }

        $colony = $criar->handle($user, $dados['name']);

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

    public function show(Request $request): JsonResponse
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
        return response()->json([
            'side' => MapaFertways::LADO,
            'capital' => ['x' => MapaFertways::CAPITAL_X, 'y' => MapaFertways::CAPITAL_Y],
            'me' => ['id' => $minha->id, 'name' => $minha->name, 'x' => $minha->x, 'y' => $minha->y],
            'colonies' => $colonias,
        ]);
    }
}
