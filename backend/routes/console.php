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

/**
 * Telemetria (A2.0.1.1). Sem estas duas linhas, tudo o que a fase A2.0 construiu fica inerte: o
 * retrato diário nunca é escrito e os eventos crescem sem fim. É o mesmo esquecimento silencioso
 * que já aconteceu com seeders de produção (D-57, D-52, D-60) — a estrutura existe, ninguém a
 * aciona, e a falha não faz barulho nenhum.
 *
 * **A ordem entre as duas não é estética.** O agregado do dia tem que existir ANTES de o evento
 * daquele dia ser descartado. Como a retenção é de 90 dias e a agregação é diária, a folga é
 * enorme — mas o horário deixa a intenção escrita: agrega às 00h10, varre às 03h.
 */
Schedule::command('fertways:telemetria-diaria')
    ->dailyAt('00:10')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * `--aplicar` porque no agendamento não há ninguém para ler um relatório e confirmar. O modo seco
 * existe para a mão humana; aqui ele só faria a tabela crescer para sempre em silêncio.
 */
Schedule::command('fertways:telemetria-limpar --aplicar')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

/**
 * As cestas dos eventos vigentes (D-232).
 *
 * A cada cinco minutos, e não a cada minuto: entregar é raro, a varredura é uma consulta a mais, e
 * o servidor tem 4 GB. Cinco minutos é a espera máxima de uma colônia recém-fundada pela cesta de
 * um evento em curso — invisível para quem acabou de escolher um nome de colônia.
 *
 * `withoutOverlapping` porque uma entrega em massa (29 colônias × 27 recursos) pode passar dos 60 s
 * num servidor ocupado. A chave única já impediria a entrega dupla; isto impede o trabalho dobrado.
 */
Schedule::command('fertways:eventos-entregar')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();
