<?php

namespace App\Domain\Ministry;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Report;
use App\Models\TradeAgreement;
use Illuminate\Support\Facades\DB;

/**
 * Passo 1 e 2 do §9.2: abertura da denúncia e triagem automática.
 *
 * A triagem não é um estado em que a denúncia dorme: ela acontece no mesmo instante em que o colono
 * aperta o botão. Ou a denúncia é rejeitada por falta de evidência, ou sobe direto à equipe por ser
 * grave, ou cai na fila de um conciliador sem impedimento. O §26.8 é explícito: "denúncia sem
 * evidência é rejeitada **automaticamente** na triagem".
 */
class AbrirDenuncia
{
    public function __construct(private Triagem $triagem) {}

    public function handle(Colony $denunciante, Colony $denunciado, string $violacao, string $texto, string $evidencia, ?int $acordoId): Report
    {
        if ($denunciante->id === $denunciado->id) {
            throw new DomainRuleException('denuncia_contra_si', 'Não se denuncia a própria colônia.');
        }

        if (! PunicaoSpecs::existe($violacao)) {
            throw new DomainRuleException('violacao_desconhecida', "O Ministério não julga isto: {$violacao}");
        }

        if (! in_array($evidencia, PunicaoSpecs::EVIDENCIAS, true)) {
            throw new DomainRuleException('evidencia_desconhecida', "Tipo de evidência inexistente: {$evidencia}");
        }

        $spec = PunicaoSpecs::violacao($violacao);
        $acordo = $this->conferirEvidencia($denunciante, $denunciado, $evidencia, $acordoId);

        return DB::transaction(function () use ($denunciante, $denunciado, $violacao, $texto, $evidencia, $acordo, $spec) {
            $denuncia = Report::create([
                'reporter_colony_id' => $denunciante->id,
                'accused_colony_id' => $denunciado->id,
                'violation' => $violacao,
                'texto' => $texto,
                'evidence_type' => $evidencia,
                'trade_agreement_id' => $acordo?->id,
                'status' => 'triagem',
                'grave' => $spec['grave'],
            ]);

            return $this->triagem->handle($denuncia);
        });
    }

    /**
     * §26.8: "Denúncia só é aceita para análise se anexar pelo menos um **Acordo de Troca
     * expirado**, print de chat, ou log de transação."
     *
     * Um acordo qualquer não serve: tem de ser um acordo **entre estes dois colonos** e que tenha
     * de fato terminado mal ou terminado. Anexar o acordo de terceiros, ou um acordo ainda em vigor,
     * seria evidência de nada — e o §26.8 quer a triagem rejeitando isso sozinha.
     *
     * `print_de_chat` é inerte: não há upload nem chat. Aceitá-lo como evidência abriria a porta
     * para denúncia sem prova nenhuma, que é exatamente o que a regra fecha. Fica gravável no
     * schema, e recusado aqui até existir chat.
     */
    private function conferirEvidencia(Colony $denunciante, Colony $denunciado, string $evidencia, ?int $acordoId): ?TradeAgreement
    {
        if ($evidencia === 'print_de_chat') {
            throw new DomainRuleException(
                'evidencia_indisponivel',
                'Não há chat nem upload de imagem em Fertways. Anexe um Acordo de Troca expirado ou um log de transação.',
            );
        }

        if ($evidencia === 'log_transacao') {
            /*
             * O log de transação par-a-par que o servidor sabe provar é o próprio Acordo de Troca:
             * um despacho avulso registra no ledger da origem, sem o destino. Enquanto for assim,
             * "log de transação" exige o mesmo anexo do acordo — e o nome fica pelo §26.8.
             */
            if ($acordoId === null) {
                throw new DomainRuleException(
                    'evidencia_faltando',
                    'Aponte o Acordo de Troca cujo log você denuncia.',
                );
            }
        }

        if ($acordoId === null) {
            throw new DomainRuleException('evidencia_faltando', 'Anexe o Acordo de Troca que serve de evidência.');
        }

        $acordo = TradeAgreement::find($acordoId);

        if (! $acordo || ! $acordo->envolve($denunciante->id) || ! $acordo->envolve($denunciado->id)) {
            throw new DomainRuleException(
                'evidencia_de_outros',
                'O acordo anexado não é entre você e o denunciado.',
            );
        }

        if ($evidencia === 'acordo_expirado' && $acordo->status !== 'quebrado') {
            throw new DomainRuleException(
                'acordo_nao_expirou',
                "O §26.8 exige um Acordo de Troca expirado. Este está {$acordo->status}.",
            );
        }

        return $acordo;
    }
}
