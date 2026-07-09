<?php

use Illuminate\Support\Facades\Schedule;

/**
 * Um tick por minuto, acionado pelo cron do sistema via `schedule:run`.
 *
 * `withoutOverlapping` importa: se um tick demorar mais de 60 s (colônia com semanas de
 * delta acumulado), o minuto seguinte não pode entrar e processar o mesmo delta de novo.
 * `runInBackground` evita que o tick atrase outras tarefas do scheduler.
 */
Schedule::command('fertways:tick')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();
