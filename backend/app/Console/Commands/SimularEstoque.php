<?php

namespace App\Console\Commands;

use App\Domain\Colony\CreateColony;
use App\Domain\Colony\TetoDoEstoque;
use App\Domain\Production\ColonyTick;
use App\Models\Colony;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * TRILHA A2.S — a rodada da A2.7, item 6: **a curva do teto de estoque cabe no ritmo do jogo?**
 *
 * ## A posição de desenho, declarada ANTES de medir
 *
 * *No nível 1, uma colônia a plena produção enche o teto em cerca de um dia; no nível 10, em cerca
 * de uma semana.* É a leitura direta do que o `BALANCEAMENTO.md` §14 manda avaliar — *"o ritmo de
 * produção por hora e o intervalo real entre sessões"* — sem violar o §1.1 do GDD, que proíbe
 * exigir login constante.
 *
 * Declarar primeiro e medir depois é deliberado: é o que impede de escolher o número e escrever a
 * justificativa em seguida. Se a medida discordar da posição, quem muda é o número — ou a posição,
 * por escrito, com a razão.
 *
 * ## Os dois erros que a §14 nomeia, e que este comando existe para achar
 *
 * - **Capacidade baixa demais** *"transforma o jogo em obrigação de login"* — encher em poucas horas
 *   obriga a voltar antes do jantar ou perder produção.
 * - **Capacidade alta demais** *"torna estoque irrelevante"* — nunca encher é o mesmo que não ter
 *   teto, e o Depósito Local volta a ser decoração.
 *
 * ## As quatro regras da trilha
 *
 * 1. **Reusa o domínio.** `ColonyTick::taxasNominais()` e `TetoDoEstoque::capacidade()` — as mesmas
 *    que o tick chama. Um simulador que reescreve a fórmula mente com aparência de autoridade.
 * 2. **Parâmetros da mesma fonte.** Lê `estoque_settings`, nunca uma cópia digitada aqui.
 * 3. **Não toca produção.** Roda dentro de uma transação que termina em `rollBack()`.
 * 4. **Saída legível**, pronta para o `BALANCEAMENTO.md`.
 *
 *     php84 artisan fertways:simular-estoque --predios=extrator_de_agua:5,gerador_de_atmosfera:5
 */
class SimularEstoque extends Command
{
    protected $signature = 'fertways:simular-estoque
        {--predios= : a colônia simulada, ex.: extrator_de_agua:5,gerador_de_atmosfera:5}
        {--capacidade-base= : sobrepõe o teto do Depósito Local nível 1}
        {--fator= : sobrepõe o fator por nível, em milésimos (1250 = 1,25×)}
        {--alvo-nivel-1=24 : horas até encher no nível 1 que a posição de desenho pede}
        {--alvo-nivel-10=168 : e no nível 10}';

    protected $description = 'Trilha A2.S: mede em quanto tempo a produção enche o teto de estoque';

    /** O nível mais alto que o Depósito Local alcança. */
    private const NIVEL_MAXIMO = 10;

    public function handle(TetoDoEstoque $teto, ColonyTick $tick): int
    {
        DB::beginTransaction();

        try {
            $this->aplicarSobreposicoes();

            $colonia = $this->coloniaDescartavel();
            $taxas = $this->taxasPositivas($tick, $colonia);

            if ($taxas === []) {
                $this->error('A colônia simulada não produz nada. Use --predios.');

                return self::FAILURE;
            }

            $this->cabecalho($colonia, $taxas);
            $this->tabela($teto, $colonia, $taxas);
        } finally {
            // Regra 3 da trilha: nada do que este comando escreveu sobrevive a ele.
            DB::rollBack();
        }

        return self::SUCCESS;
    }

    /**
     * O tempo até encher, por nível e por recurso.
     *
     * @param  array<string,int>  $taxas
     */
    private function tabela(TetoDoEstoque $teto, Colony $colonia, array $taxas): void
    {
        $gargalo = array_search(max($taxas), $taxas, true);
        $alvo1 = (float) $this->option('alvo-nivel-1');
        $alvo10 = (float) $this->option('alvo-nivel-10');
        $horas = [];

        $this->newLine();
        $this->line(sprintf('%-7s %-12s %-14s %s', 'nível', 'capacidade', 'enche em', 'leitura'));

        for ($nivel = 1; $nivel <= self::NIVEL_MAXIMO; $nivel++) {
            $this->erguerDeposito($colonia, $nivel);
            $capacidade = app(TetoDoEstoque::class)->capacidade($colonia->fresh(['buildings']), $gargalo);

            if ($capacidade === null) {
                $this->error('Teto nulo — `estoque_settings.ativo` está desligado no banco simulado?');

                return;
            }

            /*
             * Pelo recurso que satura PRIMEIRO, e não pela média: é o de maior produção que decide
             * quando o jogador tem de voltar. Média esconderia exatamente o caso que interessa —
             * mesmo princípio do gargalo que o `Populacao\Ciclo` usa para a razão de suprimento.
             */
            $h = $capacidade / $taxas[$gargalo];
            $horas[$nivel] = $h;

            $this->line(sprintf(
                '%-7d %-12d %-14s %s',
                $nivel, $capacidade, $this->tempo($h), $this->leitura($h),
            ));
        }

        $this->veredito($horas, $alvo1, $alvo10, $gargalo, $taxas[$gargalo]);
    }

    /**
     * ⚠️ A leitura de cada linha, com os dois erros que a §14 nomeia.
     *
     * As faixas não são gosto: abaixo de 6 h a colônia obriga a voltar antes do jantar, e acima de
     * duas semanas o teto nunca é atingido por quem joga em sessões de 5–10 minutos (§15) — nos dois
     * casos o Depósito Local deixa de ser decisão.
     */
    private function leitura(float $horas): string
    {
        return match (true) {
            $horas < 6 => 'apertado: vira obrigação de login (§14)',
            $horas < 20 => 'curto, mas jogável',
            $horas <= 192 => 'faixa boa: decisão de quando voltar',
            $horas <= 336 => 'folgado',
            default => 'irrelevante: nunca enche (§14)',
        };
    }

    /** @param array<int,float> $horas */
    private function veredito(array $horas, float $alvo1, float $alvo10, string $gargalo, int $taxa): void
    {
        $this->newLine();
        $this->line(sprintf(
            'Gargalo: %s a %d/h — é ele que decide quando a colônia para de render.',
            $gargalo, $taxa,
        ));
        $this->line(sprintf(
            'Posição de desenho: ~%s no nível 1 e ~%s no nível 10.',
            $this->tempo($alvo1), $this->tempo($alvo10),
        ));
        $this->line(sprintf(
            'Medido:             %s no nível 1 e %s no nível 10.',
            $this->tempo($horas[1]), $this->tempo($horas[self::NIVEL_MAXIMO]),
        ));

        // Meia ordem de grandeza para cada lado: a posição fala em "cerca de", não em igualdade.
        $bate = fn (float $medido, float $alvo) => $medido >= $alvo / 1.5 && $medido <= $alvo * 1.5;
        $ok1 = $bate($horas[1], $alvo1);
        $ok10 = $bate($horas[self::NIVEL_MAXIMO], $alvo10);

        $this->newLine();

        if ($ok1 && $ok10) {
            $this->info('✔ A curva cumpre a posição declarada nas duas pontas.');

            return;
        }

        $this->warn('⚠️ A curva NÃO cumpre a posição declarada:');

        if (! $ok1) {
            $this->line('   nível 1 fora do alvo — mexer em `capacidade_base`.');
        }

        if (! $ok10) {
            $this->line('   nível 10 fora do alvo — mexer em `capacidade_fator_milesimos`.');
        }

        $this->line('   Ou mudar a posição, POR ESCRITO e com a razão. O que não vale é ajustar o');
        $this->line('   alvo para o número que saiu.');
    }

    private function tempo(float $horas): string
    {
        return $horas < 48
            ? sprintf('%.0f h', $horas)
            : sprintf('%.1f dias', $horas / 24);
    }

    /**
     * As taxas de produção reais da colônia simulada, só as positivas.
     *
     * @return array<string,int>
     */
    private function taxasPositivas(ColonyTick $tick, Colony $colonia): array
    {
        $taxas = $tick->taxasNominais($colonia->fresh(['buildings']))['taxas'];

        return array_filter(
            $taxas,
            fn ($v, $r) => $v > 0 && ! in_array($r, TetoDoEstoque::SEM_TETO_GERAL, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @param array<string,int> $taxas */
    private function cabecalho(Colony $colonia, array $taxas): void
    {
        $p = DB::table('estoque_settings')->find(1);

        $this->info('Trilha A2.S — teto de estoque (A2.7 item 6)');
        $this->line(sprintf(
            'base=%d · fator=%.3f×/nível · produção: %s',
            $p->capacidade_base,
            $p->capacidade_fator_milesimos / 1000,
            implode(' · ', array_map(fn ($r, $v) => "{$r}:{$v}/h", array_keys($taxas), $taxas)),
        ));
    }

    private function aplicarSobreposicoes(): void
    {
        /*
         * ⚠️ Liga a chave DENTRO da transação revertida. Sem isto `TetoDoEstoque` devolveria `null`
         * para tudo e o comando mediria o nada — e a produção de verdade continua sem teto, porque
         * nada aqui sobrevive ao `rollBack()`.
         */
        $mudancas = ['ativo' => true];

        if (($v = $this->option('capacidade-base')) !== null && $v !== '') {
            $mudancas['capacidade_base'] = (int) $v;
        }

        if (($v = $this->option('fator')) !== null && $v !== '') {
            $mudancas['capacidade_fator_milesimos'] = (int) $v;
        }

        DB::table('estoque_settings')->where('id', 1)->update($mudancas);
    }

    private function coloniaDescartavel(): Colony
    {
        $u = User::create([
            'name' => 'simulador', 'nickname' => 'sim-estoque',
            'email' => 'sim-estoque@simulador.invalid', 'password' => Hash::make(bin2hex(random_bytes(8))),
        ]);

        $colonia = app(CreateColony::class)->handle($u, 'Descartável', 10, 20);

        foreach ($this->predios() as $tipo => $nivel) {
            $this->erguer($colonia, $tipo, $nivel);
        }

        return $colonia->fresh(['buildings']);
    }

    /** @return array<string,int> */
    private function predios(): array
    {
        $bruto = (string) ($this->option('predios') ?? '');

        if (trim($bruto) === '') {
            // Uma colônia madura o bastante para saturar: é do pico que a §14 fala.
            return ['extrator_de_agua' => 5, 'gerador_de_atmosfera' => 5, 'fazenda' => 5];
        }

        $predios = [];

        foreach (explode(',', $bruto) as $par) {
            [$tipo, $nivel] = array_pad(explode(':', trim($par)), 2, '1');
            $predios[trim($tipo)] = max(1, (int) $nivel);
        }

        return $predios;
    }

    private function erguerDeposito(Colony $colonia, int $nivel): void
    {
        $this->erguer($colonia, 'deposito_local', $nivel);
    }

    private function erguer(Colony $colonia, string $tipo, int $nivel): void
    {
        $existente = $colonia->buildings()->where('type', $tipo)->first();

        if ($existente) {
            $existente->update(['level' => $nivel]);

            return;
        }

        $colonia->buildings()->create(['type' => $tipo, 'level' => $nivel]);
    }
}
