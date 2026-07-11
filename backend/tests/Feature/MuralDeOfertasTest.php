<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Trade\AceitarOferta;
use App\Domain\Trade\ExpirarAcordos;
use App\Domain\Trade\ProporAcordo;
use App\Models\Colony;
use App\Models\TradeAgreement;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O mural de ofertas entre colonos (D-58).
 *
 * Uma oferta aberta é um Acordo de Troca sem contraparte. Continua **sem escrow** (D-40): anunciar
 * não tira nada do estoque, e o calote segue possível — é ele que alimenta o Ministério.
 */
class MuralDeOfertasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function colonia(string $nick, int $x, int $y): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);

        return app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y)->fresh();
    }

    private function ofertaAberta(Colony $de, ?Carbon $prazo = null): TradeAgreement
    {
        return app(ProporAcordo::class)->handle(
            $de,
            null, // sem contraparte: vai ao mural
            ['metal_bruto' => 100],
            ['agua' => 200],
            $prazo ?? now()->addDays(3),
        );
    }

    #[Test]
    public function a_oferta_sem_contraparte_nasce_aberta_no_mural(): void
    {
        $a = $this->colonia('anunciante', 10, 10);

        $oferta = $this->ofertaAberta($a);

        $this->assertNull($oferta->colony_b_id, 'ninguém do outro lado ainda');
        $this->assertSame('proposto', $oferta->status);
        // O lado sem dono mora na chave 0 até alguém aceitar.
        $this->assertSame(['agua' => 200], $oferta->terms_json['0']);
        $this->assertSame(['metal_bruto' => 100], $oferta->terms_json[(string) $a->id]);
    }

    #[Test]
    public function anunciar_no_mural_nao_reserva_nada_do_estoque(): void
    {
        $a = $this->colonia('anunciante', 10, 10);
        $antes = (int) $a->resources()->where('resource_type', 'metal_bruto')->value('amount');

        $this->ofertaAberta($a);

        // D-40 de pé: a oferta é promessa, não garantia. O calote continua real.
        $this->assertSame($antes, (int) $a->resources()->where('resource_type', 'metal_bruto')->value('amount'));
    }

    #[Test]
    public function quem_aceita_vira_a_contraparte_e_herda_o_lado_aberto(): void
    {
        $a = $this->colonia('anunciante', 10, 10);
        $b = $this->colonia('tomador', 12, 12);

        $oferta = $this->ofertaAberta($a);
        $aceita = app(AceitarOferta::class)->handle($b, $oferta);

        $this->assertSame($b->id, $aceita->colony_b_id);
        $this->assertSame('aceito', $aceita->status);
        $this->assertNotNull($aceita->accepted_at);

        // A chave 0 desapareceu: os termos voltam a ser indexados por colônia, e o resto do domínio
        // (entrega, inadimplência, reputação) nem fica sabendo que houve oferta aberta.
        $this->assertArrayNotHasKey('0', $aceita->terms_json);
        $this->assertSame(['agua' => 200], $aceita->prometido($b->id));
        $this->assertSame(['metal_bruto' => 100], $aceita->prometido($a->id));
        $this->assertSame([], $aceita->entregue($b->id));
    }

    #[Test]
    public function ninguem_aceita_a_propria_oferta(): void
    {
        $a = $this->colonia('anunciante', 10, 10);
        $oferta = $this->ofertaAberta($a);

        $this->expectExceptionMessage('não pode aceitar a sua própria oferta');
        app(AceitarOferta::class)->handle($a, $oferta);
    }

    #[Test]
    public function a_segunda_aceitacao_perde_a_corrida(): void
    {
        $a = $this->colonia('anunciante', 10, 10);
        $b = $this->colonia('rapido', 12, 12);
        $c = $this->colonia('lento', 13, 13);

        $oferta = $this->ofertaAberta($a);
        app(AceitarOferta::class)->handle($b, $oferta);

        $this->expectExceptionMessage('já tem contraparte');
        app(AceitarOferta::class)->handle($c, $oferta->fresh());
    }

    /**
     * O D-42 não some numa oferta aberta — ele só é cobrado mais tarde. Ao anunciar não há
     * contraparte, logo não há distância; na aceitação existe um par de verdade.
     */
    #[Test]
    public function quem_mora_longe_demais_para_o_prazo_nao_consegue_aceitar(): void
    {
        $a = $this->colonia('anunciante', -48, 0);

        // 12h30 passa no piso teórico do anúncio (distância zero: só as 12 h de folga)...
        $oferta = $this->ofertaAberta($a, now()->addHours(12)->addMinutes(30));

        // ...mas não na viagem de quem está no outro extremo: 95 slots, e o Caminhão de Carga (o
        // mais lento, que é o que o D-42 usa) leva 63 min só de ida — o prazo mínimo passa de 13 h.
        $longe = $this->colonia('distante', 47, 0);

        $this->expectExceptionMessage('longe demais para cumprir este prazo');
        app(AceitarOferta::class)->handle($longe, $oferta);
    }

    #[Test]
    public function a_oferta_que_ninguem_aceitou_vence_sem_punir_ninguem(): void
    {
        $a = $this->colonia('anunciante', 10, 10);
        $oferta = $this->ofertaAberta($a, now()->addHours(13));
        $confiancaAntes = $a->user->confianca_comercial;

        Carbon::setTestNow(now()->addHours(14));
        app(ExpirarAcordos::class)->handle();

        // §26.5: proposta não confirmada "não tem valor de evidência completa". Some como cancelada.
        $this->assertSame('cancelado', $oferta->fresh()->status);
        $this->assertSame($confiancaAntes, $a->user->fresh()->confianca_comercial, 'ninguém caloteou');

        Carbon::setTestNow();
    }
}
