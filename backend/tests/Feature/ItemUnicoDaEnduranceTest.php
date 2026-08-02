<?php

namespace Tests\Feature;

use App\Domain\Endurance\ComprarItem;
use App\Domain\Endurance\Instancias;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\EnduranceItem;
use App\Models\EnduranceItemInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Itens únicos da Endurance (A2.9 / GDD ALPHA 2 §11.1).
 *
 * A **regra central** da fase: *"um item marcado como único deve possuir identidade persistente e
 * histórico."* Antes disto, `unico` só fazia o painel forçar `quantidade_total = 1`.
 */
class ItemUnicoDaEnduranceTest extends TestCase
{
    use RefreshDatabase;

    private int $proximo = 0;

    private function colonia(int $fert = 100): Colony
    {
        $u = User::create([
            'name' => 'c', 'nickname' => 'u'.$this->proximo,
            'email' => 'u'.$this->proximo++.'@t.test', 'password' => Hash::make('x'),
        ]);

        return Colony::create([
            'user_id' => $u->id, 'name' => 'C'.$this->proximo, 'x' => 0, 'y' => $this->proximo,
            'fert_micro' => $fert * Colony::MICRO_POR_FERT, 'xp' => 999_999,
        ]);
    }

    private function item(string $tipo = EnduranceItem::UNICO): EnduranceItem
    {
        return EnduranceItem::create([
            'item_key' => 'peca'.$this->proximo++,
            'secao' => 'comando',
            'nome' => 'Peça',
            'tipo' => $tipo,
            'quantidade_total' => $tipo === EnduranceItem::UNICO ? 1 : 10,
            'quantidade_vendida' => 0,
            'preco_micro' => 1 * Colony::MICRO_POR_FERT,
            'vendavel_em_leilao' => true,
        ]);
    }

    // ────────────────────────────────────────────── identidade

    public function test_comprar_um_unico_cria_a_instancia_com_descobridor(): void
    {
        $c = $this->colonia();
        $item = $this->item();

        app(ComprarItem::class)->handle($c, $item->item_key);

        $i = EnduranceItemInstance::where('endurance_item_id', $item->id)->first();

        $this->assertNotNull($i, 'o único ganha instância');
        $this->assertSame($c->id, (int) $i->descobridor_colony_id);
        $this->assertSame($c->id, (int) $i->colony_id);
        $this->assertStringStartsWith('FW-U-', $i->selo);
    }

    /** ⚠️ Comum e raro continuam FUNGÍVEIS — é o que o roadmap manda, e o que evita migração dolorosa. */
    public function test_comum_e_raro_nao_ganham_instancia(): void
    {
        $c = $this->colonia();

        foreach ([EnduranceItem::COMUM, EnduranceItem::RARO] as $tipo) {
            $item = $this->item($tipo);
            app(ComprarItem::class)->handle($c, $item->item_key);

            $this->assertSame(
                0,
                EnduranceItemInstance::where('endurance_item_id', $item->id)->count(),
                "{$tipo} não tem biografia",
            );
        }
    }

    public function test_so_existe_um_de_cada_unico(): void
    {
        $item = $this->item();
        app(Instancias::class)->descobrir($this->colonia(), $item);

        $this->expectException(DomainRuleException::class);
        app(Instancias::class)->descobrir($this->colonia(), $item);
    }

    public function test_item_fungivel_nao_pode_virar_instancia(): void
    {
        $this->expectException(DomainRuleException::class);
        app(Instancias::class)->descobrir($this->colonia(), $this->item(EnduranceItem::RARO));
    }

    // ────────────────────────────────────────────── ⚠️ a história que ninguém reescreve

    /**
     * ⚠️ **O descobridor NUNCA muda.**
     *
     * É o coração do §11.1. O que faz um único valer mais que um raro não é a escassez — raro também
     * é escasso — é ele ter uma origem que ninguém pode reescrever. Se a primeira venda apagasse a
     * descoberta, o item viraria só um número 1.
     */
    public function test_o_descobridor_sobrevive_a_troca_de_dono(): void
    {
        $achou = $this->colonia();
        $comprou = $this->colonia();
        $item = $this->item();

        $i = app(Instancias::class)->descobrir($achou, $item);
        app(Instancias::class)->transferir($i, $comprou, 'leilao');

        $i = $i->fresh();

        $this->assertSame($comprou->id, (int) $i->colony_id, 'o dono mudou');
        $this->assertSame($achou->id, (int) $i->descobridor_colony_id, 'e o descobridor NÃO');
    }

    public function test_a_descoberta_e_a_primeira_linha_da_biografia(): void
    {
        $c = $this->colonia();
        $i = app(Instancias::class)->descobrir($c, $this->item());

        $primeira = $i->historico()->first();

        $this->assertSame('descoberta', $primeira->motivo);
        $this->assertNull($primeira->de_colony_id, 'não veio de mão nenhuma');
        $this->assertSame($c->id, (int) $primeira->para_colony_id);
    }

    public function test_toda_troca_de_mao_deixa_linha(): void
    {
        $a = $this->colonia();
        $b = $this->colonia();
        $i = app(Instancias::class)->descobrir($a, $this->item());

        app(Instancias::class)->transferir($i, null, 'leilao');
        app(Instancias::class)->transferir($i->fresh(), $b, 'leilao');

        $this->assertSame(3, $i->historico()->count(), 'descoberta + escrow + entrega');
    }

    /**
     * ⚠️ O histórico é **append-only**.
     *
     * A biografia de um item não pode ser editada depois, ou deixa de valer como biografia — nem por
     * nós. Mesma trava do `ledger` e do `federation_ledger`.
     */
    public function test_a_biografia_nao_pode_ser_reescrita(): void
    {
        $i = app(Instancias::class)->descobrir($this->colonia(), $this->item());
        $linha = $i->historico()->first();

        $this->expectException(RuntimeException::class);
        $linha->update(['motivo' => 'inventado']);
    }

    public function test_a_biografia_nao_pode_ser_apagada(): void
    {
        $i = app(Instancias::class)->descobrir($this->colonia(), $this->item());

        $this->expectException(RuntimeException::class);
        $i->historico()->first()->delete();
    }

    /** Escrow: dono nulo é "saiu de uma mão e ainda não chegou na outra". */
    public function test_o_escrow_do_leilao_deixa_o_item_sem_dono(): void
    {
        $i = app(Instancias::class)->descobrir($this->colonia(), $this->item());

        app(Instancias::class)->transferir($i, null, 'leilao');

        $this->assertNull($i->fresh()->colony_id);
    }

    // ────────────────────────────────────────────── telemetria de circulação

    public function test_a_circulacao_e_registrada_na_telemetria(): void
    {
        $a = $this->colonia();
        $b = $this->colonia();
        $i = app(Instancias::class)->descobrir($a, $this->item());
        app(Instancias::class)->transferir($i, $b, 'presente');

        /*
         * ⚠️ O registro é ADIADO (`adiar: true`), porque roda dentro da transação da transferência —
         * sem isso, um rollback levaria a métrica junto (D-173). O buffer descarrega no fim da
         * requisição, via `app()->terminating`, e um teste que chama o domínio direto nunca fecha
         * requisição nenhuma. Descarregar à mão aqui é reproduzir o que a produção faz sozinha.
         */
        app(\App\Domain\Telemetria\RegistrarEvento::class)->descarregar();

        $this->assertDatabaseHas('telemetry_events', ['type' => 'item_unico_circulou']);
    }

    // ────────────────────────────────────────────── a tela

    /**
     * ⚠️ "Identidade persistente" que ninguém enxerga não é identidade.
     *
     * Este teste existe porque já publiquei rota sem tela nesta base (D-180), e aqui a consequência
     * seria pior: o item teria história no banco e o jogador veria só mais uma peça.
     */
    public function test_a_listagem_traz_a_biografia_do_unico(): void
    {
        $achou = $this->colonia();
        $item = $this->item();
        app(Instancias::class)->descobrir($achou, $item);

        $corpo = $this->actingAs($achou->user)->getJson('/endurance/secoes/comando')->assertOk()->json();
        $linha = collect($corpo['itens'])->firstWhere('item_key', $item->item_key);

        $this->assertNotNull($linha['unico'], 'o único mostra a biografia');
        $this->assertSame($achou->name, $linha['unico']['descobridor']);
        $this->assertTrue($linha['unico']['e_meu']);
    }

    public function test_a_listagem_nao_inventa_biografia_para_o_fungivel(): void
    {
        $c = $this->colonia();
        $item = $this->item(EnduranceItem::COMUM);

        $corpo = $this->actingAs($c->user)->getJson('/endurance/secoes/comando')->assertOk()->json();
        $linha = collect($corpo['itens'])->firstWhere('item_key', $item->item_key);

        $this->assertNull($linha['unico']);
    }
}
