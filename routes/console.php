<?php

use App\Console\Commands\CronJob;
use App\Console\Commands\FetchEmails;
use App\Console\Commands\GatewayLogPrune;
use App\Console\Commands\MoneyCheckCommand;
use App\Console\Commands\ScheduleHeartbeatCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ScheduleHeartbeatCommand::class)->description('Updates the last scheduler run time')->everyMinute()->onOneServer();
Schedule::command(CronJob::class)->description('Runs daily to send out invoices, suspend servers, etc.')->dailyAt(config('settings.cronjob_time', '09:00'))->withoutOverlapping(120)->onOneServer();
Schedule::command(FetchEmails::class)->description('Import ticket emails using IMAP')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
// The money check (docs/money-trail.md §5, MT-3): read-only, staff mailed when it finds something new. Before the
// cron's run, so it sees the night's payments and yesterday's run. One task of the scheduler to come (ROADMAP).
Schedule::command(MoneyCheckCommand::class)->description('Checks that the money adds up (read-only)')->dailyAt('06:30')->withoutOverlapping()->onOneServer();
Schedule::command(GatewayLogPrune::class)->description('Deletes refused gateway requests that moved no money, after 90 days')->dailyAt('06:15')->withoutOverlapping()->onOneServer();
