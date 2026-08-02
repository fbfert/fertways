<?php

namespace App\Domain\Federacao;

use App\Domain\Chat\ContaSistema;
use App\Domain\Chat\EnviarMensagem;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationSetting;
use Illuminate\Support\Facades\DB;

/**
 * Propor, aceitar e romper aliança entre federações (A2.5, item 7).
 *
 * ## O cargo de Diplomata finalmente diplomata
 *
 * Ele existe desde o D-114 e só sabia convidar colônia. Aqui ele ganha o que o nome promete — e é
 * a mesma permissão de convite (`podeConvidarParaFederacao`), de propósito: quem fala por uma
 * federação com gente de fora é uma pessoa só, não duas listas diferentes que alguém teria de
 * manter em sincronia.
 *
 * ## ⚠️ Consentimento mútuo para aliar, unilateral para romper
 *
 * Propor é de Líder ou Diplomata; **aceitar é da outra federação**, nunca de quem propôs. Romper
 * não pede permissão a ninguém.
 *
 * A assimetria é deliberada: entrar exige acordo, sair não exige refém. Uma aliança que precisasse
 * do consentimento dos dois para acabar seria uma armadilha — bastaria a outra parte calar-se para
 * prender alguém num pacto que já não serve.
 *
 * ## Romper é imediato, e não há carência
 *
 * Carência puniria justamente quem foi traído: quem quebra um acordo age primeiro, e quem reage
 * ficaria travado. Se um dia isso virar problema de balanceamento, é parâmetro — não regra.
 */
class Diplomacia
{
    public function __construct(
        private readonly Aliancas $aliancas,
        private readonly EnviarMensagem $chat,
    ) {}

    /** Propõe aliança à outra federação. Só cria a proposta — o efeito nasce do aceite. */
    public function propor(Colony $autor, Federation $outra): void
    {
        DB::transaction(function () use ($autor, $outra) {
            $minha = $this->federacaoDe($autor);

            if ($minha->id === $outra->id) {
                throw new DomainRuleException('mesma_federacao', 'Uma federação não se alia a si mesma.');
            }

            $existente = $this->linha($minha->id, $outra->id);

            if ($existente) {
                throw new DomainRuleException(
                    'ja_existe',
                    $existente->status === 'aceita'
                        ? 'Vocês já são aliadas.'
                        : 'Já há uma proposta aberta entre as duas.',
                );
            }

            /*
             * O teto é conferido dos DOIS lados na proposta, e de novo no aceite.
             *
             * Aqui, porque propor sabendo que não caberia é fazer a outra perder tempo. E de novo
             * no aceite, porque entre uma coisa e outra qualquer das duas pode ter fechado outra
             * aliança — a proposta não reserva vaga.
             */
            $this->conferirTeto($minha);
            $this->conferirTeto($outra);

            DB::table('federation_alliances')->insert([
                'menor_id' => min($minha->id, $outra->id),
                'maior_id' => max($minha->id, $outra->id),
                'status' => 'proposta',
                'proposta_por_id' => $minha->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->avisar($outra, "A federação {$minha->name} propôs uma aliança.");
        });
    }

    /** Aceita a proposta da outra. Aqui nasce o efeito de jogo. */
    public function aceitar(Colony $autor, Federation $outra): void
    {
        DB::transaction(function () use ($autor, $outra) {
            $minha = $this->federacaoDe($autor);
            $linha = $this->linha($minha->id, $outra->id, travar: true);

            if (! $linha || $linha->status !== 'proposta') {
                throw new DomainRuleException('sem_proposta', 'Não há proposta de aliança dessa federação.');
            }

            /*
             * ⚠️ Quem propôs não aceita a própria proposta. Sem esta trava, um Diplomata proporia e
             * aceitaria sozinho, e o "consentimento mútuo" seria um comentário no código.
             */
            if ((int) $linha->proposta_por_id === $minha->id) {
                throw new DomainRuleException(
                    'proposta_sua',
                    'Esta proposta é sua — quem aceita é a outra federação.',
                );
            }

            // De novo, porque a proposta não reserva vaga. Ver `propor()`.
            $this->conferirTeto($minha);
            $this->conferirTeto($outra);

            DB::table('federation_alliances')->where('id', $linha->id)->update([
                'status' => 'aceita',
                'aceita_em' => now(),
                'updated_at' => now(),
            ]);

            $this->avisar($outra, "A federação {$minha->name} aceitou a aliança.");
            $this->avisar($minha, "Aliança com {$outra->name} firmada.");
        });
    }

    /** Rompe a aliança, ou recusa a proposta. Unilateral — ver o docblock da classe. */
    public function romper(Colony $autor, Federation $outra): void
    {
        DB::transaction(function () use ($autor, $outra) {
            $minha = $this->federacaoDe($autor);
            $linha = $this->linha($minha->id, $outra->id, travar: true);

            if (! $linha) {
                throw new DomainRuleException('sem_relacao', 'Não há aliança nem proposta com essa federação.');
            }

            $era = $linha->status;
            DB::table('federation_alliances')->where('id', $linha->id)->delete();

            $this->avisar($outra, $era === 'aceita'
                ? "A federação {$minha->name} rompeu a aliança."
                : "A federação {$minha->name} recusou a proposta de aliança.");
        });
    }

    /**
     * Quem fala pela federação: Líder ou Diplomata — a mesma permissão do convite (D-114).
     *
     * ⚠️ Relê a colônia com `lockForUpdate` dentro da transação. Sem isso, uma perda de cargo
     * concorrente passaria despercebida e um ex-Diplomata firmaria aliança.
     */
    private function federacaoDe(Colony $autor): Federation
    {
        $colonia = Colony::whereKey($autor->id)->lockForUpdate()->first();

        if (! $colonia?->federation_id) {
            throw new DomainRuleException('sem_federacao', 'Você não está em uma federação.');
        }

        if (! $colonia->podeConvidarParaFederacao()) {
            throw new DomainRuleException(
                'sem_permissao',
                'Só o Líder ou o Diplomata tratam de aliança com outra federação.',
            );
        }

        return Federation::whereKey($colonia->federation_id)->firstOrFail();
    }

    private function conferirTeto(Federation $federacao): void
    {
        $maximo = (int) FederationSetting::singleton()->max_aliadas;

        if (count($this->aliancas->aliadasDe($federacao->id)) >= $maximo) {
            throw new DomainRuleException(
                'teto_de_aliadas',
                "A federação {$federacao->name} já está no limite de {$maximo} aliada(s).",
            );
        }
    }

    private function linha(int $a, int $b, bool $travar = false): ?object
    {
        $q = DB::table('federation_alliances')
            ->where('menor_id', min($a, $b))
            ->where('maior_id', max($a, $b));

        return $travar ? $q->lockForUpdate()->first() : $q->first();
    }

    /**
     * Avisa a outra federação pelo chat dela.
     *
     * ⚠️ Sem isto a diplomacia acontece **em silêncio**: a outra federação só descobriria a proposta
     * abrindo a tela por conta própria. É a mesma lição do D-121, que teve de espalhar avisos por
     * seis serviços porque expulsão e transferência de liderança aconteciam sem que ninguém soubesse.
     */
    private function avisar(Federation $federacao, string $texto): void
    {
        /*
         * Só a quem pode RESPONDER — Líder e Diplomata. Avisar as doze colônias de uma proposta que
         * onze delas não podem aceitar seria ruído: o chat privado é o canal de quem age.
         */
        $destinos = Colony::where('federation_id', $federacao->id)
            ->whereIn('federation_role', [Federation::LIDER, Federation::DIPLOMATA])
            ->with('user')
            ->get();

        foreach ($destinos as $colonia) {
            if ($usuario = $colonia->user) {
                $this->chat->sistema(ContaSistema::federacao(), $usuario, $texto);
            }
        }
    }
}
