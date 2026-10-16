<?php

use App\Console\Commands\CronJob;
use App\Console\Commands\Domains\SyncCosts;
use App\Console\Commands\Domains\SyncDomains;
use App\Console\Commands\FetchEmails;
use App\Console\Commands\GatewayLogPrune;
use App\Console\Commands\MailSweep;
use App\Console\Commands\MoneyCheckCommand;
use App\Console\Commands\ScheduleHeartbeatCommand;
use App\Console\Commands\VatRecheck;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ScheduleHeartbeatCommand::class)->description('Updates the last scheduler run time')->everyMinute()->onOneServer();
Schedule::command(CronJob::class)->description('Runs daily to send out invoices, suspend servers, etc.')->dailyAt(config('settings.cronjob_time', '09:00'))->withoutOverlapping(120)->onOneServer();
Schedule::command(FetchEmails::class)->description('Import ticket emails using IMAP')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
// The money check (docs/money-trail.md §5, MT-3): read-only, staff mailed when it finds something new. Before the
// cron's run, so it sees the night's payments and yesterday's run. One task of the scheduler to come (ROADMAP).
Schedule::command(MoneyCheckCommand::class)->description('Checks that the money adds up (read-only)')->dailyAt('06:30')->withoutOverlapping()->onOneServer();
Schedule::command(SyncDomains::class)->description('Syncs domains with their registrars and moves them by their dates')->everyFourHours()->withoutOverlapping(60)->onOneServer();
Schedule::command(SyncCosts::class)->description('Reads the registrars\' costs for the TLDs (never a price)')->weeklyOn(1, '05:30')->withoutOverlapping()->onOneServer();
Schedule::command(GatewayLogPrune::class)->description('Deletes refused gateway requests that moved no money, after 90 days')->dailyAt('06:15')->withoutOverlapping()->onOneServer();
// The mail audit (docs/mail.md): mails stuck in sending or pending are marked failed, for staff to resend.
Schedule::command(MailSweep::class)->description('Marks mails stuck on their way out as failed')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
// EU VAT (PR G, docs/eu-vat.md): pending numbers until VIES answers, the others monthly. Nothing when EU VAT is off.
Schedule::command(VatRecheck::class)->description('Checks EU VAT numbers with VIES')->hourlyAt(17)->withoutOverlapping()->onOneServer();
