<?php

namespace App\Domain\GuerraFederativa;

use App\Domain\News\PublicarNoticia;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\WarSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Declarar e encerrar neutralidade (A2.10, decisão 12).
 *
 * ## Entrar é imediato; sair, não
 *
 * A assimetria é o que torna a neutralidade uma escolha em vez de um escudo. Ver o docblock da
 * migration `neutralidade_declarada`: sem carência para sair, largar o abrigo no instante do ataque
 * seria a jogada certa sempre, e a guerra nunca aconteceria.
 *
 * ## Simétrica
 *
 * Neutra não pode ser declarada **e não pode declarar**. Quem não entra na guerra não entra dos dois
 * lados — é o que a palavra significa, e é o custo que paga a proteção.
 */
class Neutralidade
{
    public function __construct(
        private readonly PublicarNoticia $noticias,
        private readonly EmGuerra $emGuerra,
    ) {}

    /** A federação está protegida neste instante? */
    public function vigente(Federation $federacao, ?CarbonInterface $quando = null): bool
    {
        $quando ??= now();

        if ($federacao->neutra_desde === null) {
            return false;
        }

        /*
         * Pedido de saída em curso: continua neutra ATÉ a data chegar. É a carência, e é o que
         * impede o escudo de ser largado na hora do ataque.
         */
        if ($federacao->neutralidade_termina_em !== null) {
            return $quando->getTimestamp() < $federacao->neutralidade_termina_em->getTimestamp();
        }

        return true;
    }

    public function declarar(Colony $autor): Federation
    {
        return DB::transaction(function () use ($autor) {
            $f = $this->federacaoDe($autor);

            if ($this->vigente($f)) {
                throw new DomainRuleException('ja_neutra', 'A sua federação já é neutra.');
            }

            /*
             * ⚠️ Não se declara neutralidade estando em guerra: seria fugir do que já começou. A
             * saída de uma guerra em curso é a capitulação (decisão 9), no ar desde o D-206.
             */
            if ($this->emGuerra->federacaoEmGuerra($f->id)) {
                throw new DomainRuleException(
                    'em_guerra',
                    'Não dá para se declarar neutro no meio de uma guerra. A saída é a capitulação.',
                );
            }

            $f->update(['neutra_desde' => now(), 'neutralidade_termina_em' => null]);

            /*
             * Pública, como a declaração de guerra. Neutralidade secreta não protegeria ninguém: o
             * agressor precisa poder saber, antes de gastar o fundo, que aquela porta está fechada.
             */
            $this->noticias->publicar(
                "Neutralidade declarada: {$f->name}",
                "A federação {$f->name} declarou-se neutra. Não pode ser declarada, e não declara.",
                'Ministério das Relações',
            );

            return $f->fresh();
        });
    }

    /** Pede para sair. Só vale quando a carência acabar — e até lá a proteção continua. */
    public function encerrar(Colony $autor): Federation
    {
        return DB::transaction(function () use ($autor) {
            $f = $this->federacaoDe($autor);

            if (! $this->vigente($f)) {
                throw new DomainRuleException('nao_e_neutra', 'A sua federação não é neutra.');
            }

            if ($f->neutralidade_termina_em !== null) {
                throw new DomainRuleException(
                    'ja_pedido',
                    'A saída já foi pedida, e vale a partir de '
                        .$f->neutralidade_termina_em->format('d/m H:i').'.',
                );
            }

            $horas = (int) WarSetting::singleton()->neutralidade_carencia_horas;
            $f->update(['neutralidade_termina_em' => now()->addHours($horas)]);

            $this->noticias->publicar(
                "Fim da neutralidade anunciado: {$f->name}",
                "A federação {$f->name} deixa de ser neutra em "
                    .$f->fresh()->neutralidade_termina_em->format('d/m/Y H:i').'.',
                'Ministério das Relações',
            );

            return $f->fresh();
        });
    }

    /**
     * Limpa o estado de quem já cumpriu a carência.
     *
     * ⚠️ Não é obrigatório para a regra funcionar — `vigente()` já compara com o relógio e devolve
     * `false` sozinho. Existe para que o **dado** não fique mentindo: uma federação com
     * `neutra_desde` preenchido e carência vencida não é neutra, e deixar as duas colunas assim
     * faria qualquer consulta direta ao banco concluir o contrário.
     */
    public function limparVencidas(CarbonInterface $agora): int
    {
        return Federation::whereNotNull('neutralidade_termina_em')
            ->where('neutralidade_termina_em', '<=', $agora)
            ->update(['neutra_desde' => null, 'neutralidade_termina_em' => null]);
    }

    /** Líder ou Diplomata — a mesma permissão da aliança e da guerra. Quem fala com fora é um só. */
    private function federacaoDe(Colony $autor): Federation
    {
        $colonia = Colony::whereKey($autor->id)->lockForUpdate()->first();

        if (! $colonia?->federation_id) {
            throw new DomainRuleException('sem_federacao', 'Você não está em uma federação.');
        }

        if (! $colonia->podeConvidarParaFederacao()) {
            throw new DomainRuleException(
                'sem_permissao',
                'Só o Líder ou o Diplomata declaram a neutralidade da federação.',
            );
        }

        return Federation::whereKey($colonia->federation_id)->lockForUpdate()->firstOrFail();
    }
}
