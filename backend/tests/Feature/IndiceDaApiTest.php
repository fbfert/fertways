<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O índice em `/central/` é a única página que alguém abre no navegador para saber se a API está
 * de pé. Ele era uma lista digitada à mão e envelheceu: mostrava um backend sem `/vehicles` nem
 * `/market/*` muito depois de os dois existirem, e passava a impressão de que a API estava
 * quebrada. Agora deriva do roteador, e estes testes garantem que continue derivando.
 */
class IndiceDaApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function o_indice_responde_sem_autenticacao(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertJsonPath('service', 'FERTWAYS API')
            ->assertJsonPath('health', 'GET /up');
    }

    #[Test]
    public function o_indice_lista_toda_rota_registrada_e_marca_as_protegidas(): void
    {
        $endpoints = $this->getJson('/')->json('endpoints');

        // Uma amostra de cada fatia do MVP. Se alguém acrescentar uma rota, ela aparece sozinha;
        // se remover uma destas, o teste avisa em vez de a página mentir em silêncio.
        $this->assertContains('POST /login', $endpoints);
        $this->assertContains('GET /colony (autenticado)', $endpoints);
        $this->assertContains('POST /vehicles/{vehicle}/dispatch (autenticado)', $endpoints);
        $this->assertContains('POST /vehicles/{vehicle}/withdraw (autenticado)', $endpoints);
        $this->assertContains('GET /market/account (autenticado)', $endpoints);
        $this->assertContains('POST /market/orders (autenticado)', $endpoints);
        $this->assertContains('DELETE /market/orders/{order} (autenticado)', $endpoints);
    }

    #[Test]
    public function o_indice_esconde_as_rotas_internas_do_framework(): void
    {
        $endpoints = $this->getJson('/')->json('endpoints');

        foreach ($endpoints as $e) {
            $this->assertStringNotContainsString('/storage/', $e);
            $this->assertStringNotContainsString('/sanctum/', $e);
        }

        // `/up` tem campo próprio. Comparar a linha inteira, não substring: `/upgrade` contém `/up`.
        $this->assertNotContains('GET /up', $endpoints);
        $this->assertContains('POST /buildings/{building}/upgrade (autenticado)', $endpoints);
    }

    #[Test]
    public function a_raiz_nao_revela_a_versao_do_laravel(): void
    {
        $corpo = $this->get('/')->getContent();

        $this->assertStringNotContainsString('Laravel', $corpo);
    }
}
