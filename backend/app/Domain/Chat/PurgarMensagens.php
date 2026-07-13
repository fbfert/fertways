<?php

namespace App\Domain\Chat;

use App\Models\ChatMessage;
use Carbon\CarbonInterface;

/**
 * A retenção PUBLICADA do §08/§10.3 (D-77) — roda no tick, e os prazos não são nossos:
 *
 *   global e regional   180 dias
 *   vizinhança           90 dias
 *   privadas             indefinido no lançamento ("sem prazo de expiração") — NÃO purga
 *
 * ⚠️ O GDD preserva "evidência de caso até conclusão + 90 dias". Hoje nenhuma denúncia aponta para
 * mensagem de chat (a evidência do §26.8 é Acordo/log), então não há vínculo a proteger; no dia em
 * que a denúncia anexar mensagem, a purga tem de aprender a desviar dela. Registrado no D-77.
 */
class PurgarMensagens
{
    public function handle(CarbonInterface $agora): int
    {
        $apagadas = ChatMessage::whereIn('channel', ['global', 'regiao:nucleo', 'regiao:nordeste', 'regiao:sudeste', 'regiao:sudoeste', 'regiao:noroeste'])
            ->where('created_at', '<', $agora->copy()->subDays(180))
            ->delete();

        $apagadas += ChatMessage::where('channel', 'vizinhanca')
            ->where('created_at', '<', $agora->copy()->subDays(90))
            ->delete();

        return $apagadas;
    }
}
