<?php

namespace App\Domain\Cargos;

use App\Exceptions\DomainRuleException;
use App\Models\CivicPost;
use App\Models\DuvidaDoEspacoporto;
use App\Models\Feedback;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * O ato do Atendente do Espaçoporto (§14.2; D-270): *"responde a fluxos predefinidos e encaminha
 * dúvidas; não altera reservas, fretes ou rotas"*.
 *
 * Qualquer colono pergunta; só o Atendente responde ou encaminha — nunca a própria dúvida. O bônus do
 * cargo sai quando **quem perguntou** diz que a resposta ajudou, com o teto semanal dos cargos. Assim
 * o bônus paga atendimento que serviu, e não volume de respostas.
 *
 * Encaminhar é o caminho de quem não sabe ou não pode responder (um defeito, um caso da equipe): a
 * dúvida vira uma "dúvida" na aba Feedback do painel, em nome de quem perguntou. Não paga bônus.
 */
class AtendimentoDoEspacoporto
{
    /** Dúvidas abertas por colono, no máximo. */
    public const ABERTAS_POR_COLONO = 3;

    public function perguntar(User $colono, string $pergunta): DuvidaDoEspacoporto
    {
        $abertas = DuvidaDoEspacoporto::where('user_id', $colono->id)
            ->whereNull('respondida_em')->whereNull('encaminhada_em')->count();

        if ($abertas >= self::ABERTAS_POR_COLONO) {
            throw new DomainRuleException('duvidas_demais', 'Você já tem '.self::ABERTAS_POR_COLONO.' dúvidas esperando resposta.');
        }

        return DuvidaDoEspacoporto::create(['user_id' => $colono->id, 'pergunta' => trim($pergunta)]);
    }

    public function responder(User $atendente, int $id, string $resposta): DuvidaDoEspacoporto
    {
        return $this->atender($atendente, $id, fn (DuvidaDoEspacoporto $d) => $d->update([
            'resposta' => trim($resposta),
            'atendente_user_id' => $atendente->id,
            'respondida_em' => now(),
        ]));
    }

    public function encaminhar(User $atendente, int $id): DuvidaDoEspacoporto
    {
        return $this->atender($atendente, $id, function (DuvidaDoEspacoporto $d) use ($atendente) {
            $autor = $d->user;
            Feedback::create([
                'user_id' => $autor->id,
                'colony_id' => $autor->colony?->id,
                'email' => $autor->email,
                'colony_name' => $autor->colony?->name,
                'nickname' => $autor->nickname,
                'tipo' => 'duvida',
                'assunto' => 'Espaçoporto — encaminhada pelo Atendente '.$atendente->nickname,
                'mensagem' => $d->pergunta,
            ]);
            $d->update(['atendente_user_id' => $atendente->id, 'encaminhada_em' => now()]);
        });
    }

    public function avaliar(User $colono, int $id, bool $ajudou): DuvidaDoEspacoporto
    {
        return DB::transaction(function () use ($colono, $id, $ajudou) {
            $d = DuvidaDoEspacoporto::whereKey($id)->lockForUpdate()->first();

            if (! $d || $d->user_id !== $colono->id) {
                throw new DomainRuleException('duvida_alheia', 'Só quem perguntou avalia a resposta.');
            }
            if ($d->respondida_em === null) {
                throw new DomainRuleException('sem_resposta', 'Esta dúvida ainda não foi respondida.');
            }
            if ($d->avaliada_em !== null) {
                throw new DomainRuleException('ja_avaliada', 'Você já avaliou esta resposta.');
            }

            $d->update(['ajudou' => $ajudou, 'avaliada_em' => now()]);

            $atendente = $d->atendente_user_id ? User::find($d->atendente_user_id) : null;
            $colonia = $atendente?->colony;

            if ($ajudou && $colonia) {
                $valor = min(
                    CargosCivicosSpecs::BONUS_MICRO,
                    TetoSemanal::livre($atendente->id, CargosCivicosSpecs::ATENDENTE_DO_ESPACOPORTO),
                );

                if ($valor > 0) {
                    DB::table('colonies')->where('id', $colonia->id)->increment('fert_micro', $valor);
                    Ledger::create([
                        'colony_id' => $colonia->id,
                        'type' => 'bonus_cargo_civico',
                        'amount' => $valor,
                        'resource_type' => null,
                        'ref' => 'cargo:'.CargosCivicosSpecs::ATENDENTE_DO_ESPACOPORTO.":{$atendente->id}:bonus:duvida:{$d->id}",
                        'created_at' => now(),
                    ]);
                }
            }

            return $d->fresh();
        });
    }

    public function ehAtendente(User $u): bool
    {
        return CivicPost::where('user_id', $u->id)
            ->where('kind', CargosCivicosSpecs::ATENDENTE_DO_ESPACOPORTO)
            ->whereNull('suspenso_em')->exists();
    }

    private function atender(User $atendente, int $id, callable $acao): DuvidaDoEspacoporto
    {
        if (! $this->ehAtendente($atendente)) {
            throw new DomainRuleException('sem_cargo', 'Você não é Atendente do Espaçoporto (ou está suspenso).');
        }

        return DB::transaction(function () use ($atendente, $id, $acao) {
            $d = DuvidaDoEspacoporto::whereKey($id)->lockForUpdate()->first();

            if (! $d || ! $d->aberta()) {
                throw new DomainRuleException('duvida_fechada', 'Esta dúvida já foi atendida.');
            }
            if ($d->user_id === $atendente->id) {
                throw new DomainRuleException('propria_duvida', 'O Atendente não atende a própria dúvida.');
            }

            $acao($d);

            return $d->fresh();
        });
    }
}
