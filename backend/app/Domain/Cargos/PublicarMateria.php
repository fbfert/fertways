<?php

namespace App\Domain\Cargos;

use App\Domain\News\PublicarNoticia;
use App\Exceptions\DomainRuleException;
use App\Models\CivicPost;
use App\Models\Ledger;
use App\Models\News;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * O "ato" do Repórter (§14.2): "escreve resumos e notícias sobre eventos do servidor". Publica no
 * MESMO mural da Central de Notícias que o operador já usa (`News`, D-55), com `kind = 'boletim'`
 * em vez de `'comunicado'` — a distinção que o schema já reservava (`enum('kind', ['comunicado',
 * 'boletim'])`, D-55) sem nada nunca ter usado o segundo valor. Sem tabela nova, sem fila de
 * aprovação: como toda escrita livre já existente no jogo (Chat, evidência de denúncia), confia-se
 * no autor — a v32 pede "conteúdo aprovado", mas construir uma fila de moderação para isso seria
 * inventar um mecanismo que nenhuma outra escrita de jogador tem.
 */
class PublicarMateria
{
    public function handle(User $colono, string $titulo, string $corpo): News
    {
        $ocupa = CivicPost::where('user_id', $colono->id)
            ->where('kind', CargosCivicosSpecs::REPORTER)
            ->whereNull('suspenso_em')
            ->exists();

        if (! $ocupa) {
            throw new DomainRuleException('sem_cargo', 'Você não é Repórter (ou está suspenso).');
        }

        $colonia = $colono->colony;

        return DB::transaction(function () use ($colono, $colonia, $titulo, $corpo) {
            $noticia = app(PublicarNoticia::class)->publicar($titulo, $corpo, $colono->nickname);
            $noticia->forceFill(['kind' => 'boletim'])->save();

            if ($colonia) {
                $livre = TetoSemanal::livre($colono->id, CargosCivicosSpecs::REPORTER);
                $valor = min(CargosCivicosSpecs::BONUS_MICRO, $livre);

                if ($valor > 0) {
                    DB::table('colonies')->where('id', $colonia->id)->increment('fert_micro', $valor);

                    Ledger::create([
                        'colony_id' => $colonia->id,
                        'type' => 'bonus_cargo_civico',
                        'amount' => $valor,
                        'resource_type' => null,
                        'ref' => "cargo:reporter:{$colono->id}:bonus:{$noticia->id}",
                        'created_at' => now(),
                    ]);
                }
            }

            return $noticia;
        });
    }
}
