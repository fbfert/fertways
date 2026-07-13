<?php

namespace App\Domain\Missoes;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * As janelas de tempo do §06 (D-78).
 *
 * A SEMANAL é publicada com hora: "Qua 07h → Ter 23h59". A diária o GDD não data — as 07h da
 * semanal viraram a régua do dia inteiro (leitura registrada): o "dia de missão" corre de 07h a
 * 07h, e ninguém perde a diária por dormir até tarde na virada da meia-noite.
 */
final class Janela
{
    /** O começo do dia de missão corrente (07h). */
    public static function diaAtual(?CarbonInterface $agora = null): Carbon
    {
        $agora = Carbon::instance($agora ?? now());
        $inicio = $agora->copy()->setTime(7, 0);

        return $agora->lt($inicio) ? $inicio->subDay() : $inicio;
    }

    public static function proximoDia(?CarbonInterface $agora = null): Carbon
    {
        return self::diaAtual($agora)->addDay();
    }

    /** O começo da janela semanal corrente: a última quarta às 07h. */
    public static function semanaAtual(?CarbonInterface $agora = null): Carbon
    {
        $agora = Carbon::instance($agora ?? now());
        $quarta = $agora->copy()->startOfWeek(CarbonInterface::WEDNESDAY)->setTime(7, 0);

        return $agora->lt($quarta) ? $quarta->subWeek() : $quarta;
    }

    /** O fim publicado: terça 23h59 (há um vão até quarta 07h — o GDD o desenhou assim). */
    public static function fimDaSemana(?CarbonInterface $agora = null): Carbon
    {
        return self::semanaAtual($agora)->addDays(6)->setTime(23, 59, 59);
    }
}
