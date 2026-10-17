<?php

namespace App\Domain\Ministry;

use App\Exceptions\DomainRuleException;
use App\Models\AvaliacaoDeComercio;
use App\Models\ChatMessage;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\FederationWar;
use App\Models\WarSetting;
use Illuminate\Support\Facades\DB;

/**
 * As três provas que os sistemas novos trouxeram ao Ministério (D-288). Cada uma responde à mesma
 * pergunta do §26.8: isto prova que ESTE colono fez ISTO a QUEM denuncia? Prova de terceiros, ou de
 * nada, a triagem recusa sozinha.
 *
 * Cada método devolve as colunas que a denúncia grava.
 */
class ProvasDoMinisterio
{
    /** Canais que qualquer colono pode ter lido. A privada só vale entre as duas partes. */
    private const PUBLICOS_PREFIXOS = ['global', 'federacao', 'regiao:', 'vizinhanca'];

    /**
     * A mensagem do chat (`print_de_chat`). Não é imagem: é a mensagem que o servidor guardou, e por
     * isso não se forja. Copiada para a denúncia, porque a purga do chat a apagaria em 90 ou 180 dias.
     *
     * @return array<string, mixed>
     */
    public function mensagem(Colony $denunciante, Colony $denunciado, ?int $id): array
    {
        $msg = $id ? ChatMessage::find($id) : null;

        if (! $msg) {
            throw new DomainRuleException('evidencia_faltando', 'Aponte a mensagem do chat que você denuncia.');
        }

        $publica = collect(self::PUBLICOS_PREFIXOS)->contains(fn ($p) => str_starts_with($msg->channel, $p));
        $privadaComigo = $msg->channel === 'privada' && (int) $msg->recipient_user_id === (int) $denunciante->user_id;

        if ((int) $msg->user_id !== (int) $denunciado->user_id || (! $publica && ! $privadaComigo)) {
            throw new DomainRuleException('evidencia_de_outros', 'Esta mensagem não é do denunciado, ou não foi dita onde você podia ler.');
        }

        return [
            'chat_message_id' => $msg->id,
            'chat_snapshot' => ['body' => $msg->body, 'channel' => $msg->channel, 'created_at' => $msg->created_at?->toIso8601String()],
        ];
    }

    /**
     * O combate (`registro_de_combate`), para "ataque a aliado" e "quebra de tratado".
     *
     * - **Ataque a aliado:** as federações dos dois lados, carimbadas no ataque, eram a mesma ou
     *   aliadas por aliança aceita antes dele.
     * - **Quebra de tratado:** o ataque partiu dentro da paz que um tratado comprou entre as duas
     *   federações — do fim da guerra por tratado até o fim do cooldown do par (GDD §10). O cooldown
     *   só barra a guerra federativa; o ataque a zona continua possível, e é esse ataque que quebra a
     *   palavra dada.
     *
     * @return array<string, mixed>
     */
    public function combate(Colony $denunciante, Colony $denunciado, string $violacao, ?int $id): array
    {
        $c = $id ? Combat::find($id) : null;

        if (! $c) {
            throw new DomainRuleException('evidencia_faltando', 'Aponte o combate que você denuncia.');
        }

        if ((int) $c->attacker_colony_id !== $denunciado->id || (int) $c->defender_colony_id !== $denunciante->id) {
            throw new DomainRuleException('evidencia_de_outros', 'Neste combate o denunciado não atacou você.');
        }

        $fa = $c->atacante_federation_id;
        $fd = $c->defensor_federation_id;

        if (! $fa || ! $fd) {
            throw new DomainRuleException(
                'sem_federacoes',
                'Este combate não tem as federações registradas (ataque anterior ao registro, ou alguém estava sem federação).',
            );
        }

        if ($violacao === 'ataque_a_aliado') {
            $aliadas = $fa === $fd || DB::table('federation_alliances')
                ->where('status', 'aceita')
                ->where('menor_id', min($fa, $fd))->where('maior_id', max($fa, $fd))
                ->where('updated_at', '<=', $c->created_at)
                ->exists();

            if (! $aliadas) {
                throw new DomainRuleException('nao_eram_aliados', 'As federações não eram aliadas quando o ataque partiu.');
            }
        }

        if ($violacao === 'quebra_de_tratado') {
            $horas = (int) WarSetting::singleton()->federativa_cooldown_horas;

            $tratado = FederationWar::where('status', 'tratado')
                ->where(fn ($q) => $q
                    ->where(fn ($w) => $w->where('declarante_id', $fa)->where('alvo_id', $fd))
                    ->orWhere(fn ($w) => $w->where('declarante_id', $fd)->where('alvo_id', $fa)))
                ->whereNotNull('encerrada_em')
                ->where('encerrada_em', '<=', $c->created_at)
                ->orderByDesc('encerrada_em')
                ->first();

            if (! $tratado || $tratado->encerrada_em->copy()->addHours($horas)->lt($c->created_at)) {
                throw new DomainRuleException('sem_tratado_vigente', 'Não havia tratado de paz vigente entre as federações quando o ataque partiu.');
            }
        }

        return ['combat_id' => $c->id];
    }

    /**
     * O que este colono tem como prova contra aquele, para a tela escolher em vez de adivinhar ids:
     * as mensagens do denunciado que ele pôde ler, os combates em que o denunciado o atacou e as
     * avaliações que o denunciado lhe deu. As 20 mais recentes de cada.
     *
     * @return array{mensagens: list<array<string, mixed>>, combates: list<array<string, mixed>>, avaliacoes: list<array<string, mixed>>}
     */
    public function candidatas(Colony $eu, Colony $acusado): array
    {
        $mensagens = ChatMessage::where('user_id', $acusado->user_id)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('channel', 'privada')->where('recipient_user_id', $eu->user_id))
                ->orWhereIn('channel', ['global', 'federacao', 'vizinhanca'])
                ->orWhere('channel', 'like', 'regiao:%'))
            ->orderByDesc('id')->limit(20)->get()
            ->map(fn (ChatMessage $m) => ['id' => $m->id, 'canal' => $m->channel, 'texto' => $m->body, 'em' => $m->created_at])
            ->values()->all();

        $combates = Combat::where('attacker_colony_id', $acusado->id)->where('defender_colony_id', $eu->id)
            ->orderByDesc('id')->limit(20)->get()
            ->map(fn (Combat $c) => ['id' => $c->id, 'tipo' => $c->tipo, 'zona_id' => $c->zone_id, 'em' => $c->created_at,
                'com_federacoes' => $c->atacante_federation_id !== null && $c->defensor_federation_id !== null])
            ->values()->all();

        $avaliacoes = AvaliacaoDeComercio::where('autor_user_id', $acusado->user_id)->where('avaliado_user_id', $eu->user_id)
            ->whereNull('anulada_em')->orderByDesc('id')->limit(20)->get()
            ->map(fn (AvaliacaoDeComercio $a) => ['id' => $a->id, 'estrelas' => $a->estrelas, 'comentario' => $a->comentario,
                'acordo_id' => $a->trade_agreement_id, 'em' => $a->created_at])
            ->values()->all();

        return ['mensagens' => $mensagens, 'combates' => $combates, 'avaliacoes' => $avaliacoes];
    }

    /**
     * A avaliação (`avaliacao`): dada PELO denunciado A quem denuncia, e ainda de pé.
     *
     * @return array<string, mixed>
     */
    public function avaliacao(Colony $denunciante, Colony $denunciado, ?int $id): array
    {
        $av = $id ? AvaliacaoDeComercio::find($id) : null;

        if (! $av) {
            throw new DomainRuleException('evidencia_faltando', 'Aponte a avaliação que você denuncia.');
        }

        if ((int) $av->autor_user_id !== (int) $denunciado->user_id || (int) $av->avaliado_user_id !== (int) $denunciante->user_id) {
            throw new DomainRuleException('evidencia_de_outros', 'Esta avaliação não foi dada pelo denunciado a você.');
        }

        if ($av->anulada_em !== null) {
            throw new DomainRuleException('avaliacao_ja_anulada', 'Esta avaliação já foi anulada.');
        }

        return ['avaliacao_id' => $av->id];
    }
}
