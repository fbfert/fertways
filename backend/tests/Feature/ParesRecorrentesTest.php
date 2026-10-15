<?php

namespace Tests\Feature;

use App\Domain\GuerraFederativa\ParesRecorrentes;
use App\Models\Admin;
use App\Models\Federation;
use App\Models\FederationWar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Decisão 11 da guerra federativa: "travas econômicas + **detecção com revisão humana**" (D-261).
 *
 * O GDD_GUERRA_FEDERATIVA §15 recomenda *"não tentar impedir por regra, e sim tornar caro e
 * visível"*, e o item 4 é a parte visível: *"auditoria de operador com um relatório de pares
 * recorrentes"*. Nada aqui pune ninguém sozinho — punir irmão que joga junto é pior do que deixar
 * passar um trapaceiro. O jogo aponta; o operador decide, e a decisão fica registrada.
 */
class ParesRecorrentesTest extends TestCase
{
    use RefreshDatabase;

    private int $proximo = 0;

    private function federacao(): Federation
    {
        return Federation::create(['name' => 'Fed'.$this->proximo, 'tag' => 'P'.$this->proximo++]);
    }

    private function guerra(Federation $a, Federation $b, string $motivo = 'prazo', int $diasAtras = 30): FederationWar
    {
        return FederationWar::create([
            'declarante_id' => $a->id, 'alvo_id' => $b->id,
            'comeca_em' => now()->subDays($diasAtras), 'termina_em' => now()->subDays($diasAtras - 7),
            'status' => $motivo === 'capitulacao' ? 'capitulada' : 'encerrada',
            'encerrada_em' => now()->subDays($diasAtras - 7), 'motivo_fim' => $motivo,
        ]);
    }

    private function dono(): Admin
    {
        return Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::DONO,
        ]);
    }

    /** O par conta nos DOIS sentidos: quem declara a quem não muda que são as mesmas duas. */
    public function test_o_par_e_contado_nos_dois_sentidos(): void
    {
        [$a, $b, $c] = [$this->federacao(), $this->federacao(), $this->federacao()];
        $this->guerra($a, $b, 'capitulacao', 60);
        $this->guerra($b, $a, 'capitulacao', 30);
        $this->guerra($a, $c);

        $pares = app(ParesRecorrentes::class)->listar();
        $ab = $pares->first(fn ($p) => $p['federacoes'] === [$a->id, $b->id]);

        $this->assertSame(2, $ab['guerras']);
        $this->assertSame(2, $ab['capitulacoes']);
        $this->assertTrue($ab['recorrente']);
        $this->assertFalse($pares->first(fn ($p) => $p['federacoes'] === [$a->id, $c->id])['recorrente']);
        $this->assertSame([$a->id, $b->id], $pares->first()['federacoes'], 'o mais suspeito primeiro');
    }

    public function test_o_painel_da_guerra_mostra_os_pares(): void
    {
        [$a, $b] = [$this->federacao(), $this->federacao()];
        $this->guerra($a, $b, 'capitulacao', 60);
        $this->guerra($b, $a, 'capitulacao', 30);

        $this->actingAs($this->dono(), 'admin')->get('/admin/guerra')
            ->assertOk()
            ->assertSee('data-par-recorrente="'.$a->id.'-'.$b->id.'"', false);
    }

    /** A revisão é humana, registrada, e não muda nada no jogo — só diz o que o operador concluiu. */
    public function test_o_operador_registra_o_veredito_e_ele_fica_auditado(): void
    {
        [$a, $b] = [$this->federacao(), $this->federacao()];
        $this->guerra($a, $b, 'capitulacao', 60);
        $this->guerra($b, $a, 'capitulacao', 30);

        $this->actingAs($this->dono(), 'admin')->post('/admin/guerra/pares', [
            'federacao_a' => $b->id, 'federacao_b' => $a->id,      // a ordem do formulário não importa
            'veredito' => 'legitimo', 'nota' => 'Dois irmãos, rivalidade de verdade.',
        ])->assertSessionHas('ok');

        $par = app(ParesRecorrentes::class)->listar()->first();
        $this->assertSame('legitimo', $par['revisao']['veredito']);
        $this->assertTrue(DB::table('audit_log')->where('acao', 'guerra.par_revisado')->exists());
        $this->assertSame('capitulada', FederationWar::first()->status, 'a revisão não mexe no jogo');
    }

    public function test_o_veredito_e_um_de_dois(): void
    {
        [$a, $b] = [$this->federacao(), $this->federacao()];

        $this->actingAs($this->dono(), 'admin')->post('/admin/guerra/pares', [
            'federacao_a' => $a->id, 'federacao_b' => $b->id, 'veredito' => 'banir', 'nota' => 'x',
        ])->assertSessionHasErrors('veredito');
    }
}
