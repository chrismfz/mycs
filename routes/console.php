<?php

use App\Console\Commands\CronJob;
use App\Console\Commands\Domains\ReadBalances;
use App\Console\Commands\Domains\SyncCosts;
use App\Console\Commands\Domains\SyncDomains;
use App\Console\Commands\FetchTicketMail;
use App\Console\Commands\GatewayLogPrune;
use App\Console\Commands\MailSweep;
use App\Console\Commands\MoneyCheckCommand;
use App\Console\Commands\ReadBankAlerts;
use App\Console\Commands\ReadGatewayBalances;
use App\Console\Commands\RegistrarDebugLogPrune;
use App\Console\Commands\ScheduleHeartbeatCommand;
use App\Console\Commands\SyncCpanelAccounts;
use App\Console\Commands\SyncMailcowDomains;
use App\Console\Commands\SyncProxmoxGuests;
use App\Console\Commands\SyncServiceUsage;
use App\Console\Commands\SyncSslOrders;
use App\Console\Commands\VatRecheck;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ScheduleHeartbeatCommand::class)->description('Updates the last scheduler run time')->everyMinute()->onOneServer();
Schedule::command(CronJob::class)->description('Runs daily to send out invoices, suspend servers, etc.')->dailyAt(config('settings.cronjob_time', '09:00'))->withoutOverlapping(120)->onOneServer();
// Suspensions and terminations on their days and from their hour (Settings → Automation): the daily run may be at night.
Schedule::command(CronJob::class, ['--dunning-only'])->description('Suspends and terminates overdue services, on the days and from the time set')->hourlyAt(5)->withoutOverlapping(60)->onOneServer();
Schedule::command(FetchTicketMail::class)->description('Reads the departments\' mailboxes into tickets (those with polling on)')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
// The money check (docs/money-trail.md §5, MT-3): read-only, staff mailed when it finds something new. In the morning,
// after the night's cron run and payments (cronjob_time, 00:00 on the devbox). One task of the scheduler to come (ROADMAP).
Schedule::command(MoneyCheckCommand::class)->description('Checks that the money adds up (read-only)')->dailyAt('06:30')->withoutOverlapping()->onOneServer();
Schedule::command(SyncDomains::class, ['--scheduled'])->description('Syncs a batch of domains with their registrars (its hours and size in Settings → Domains) and moves them by their dates')->everyTenMinutes()->withoutOverlapping(60)->onOneServer();
Schedule::command(SyncServiceUsage::class)->description('Reads every live service\'s disk and bandwidth usage from its server (cPanel)')->cron('17 */4 * * *')->withoutOverlapping(60)->onOneServer();
Schedule::command(ReadBankAlerts::class)->description('Reads the banks\' alert emails into Payments tracking (the mailbox untouched)')->everyThreeMinutes()->withoutOverlapping(15)->onOneServer();
Schedule::command(ReadGatewayBalances::class)->description('Reads what Stripe and PayPal hold (Bank totals)')->hourlyAt(13)->withoutOverlapping(10)->onOneServer();
Schedule::command(SyncProxmoxGuests::class)->description('Reads the VMs and containers of every Proxmox cluster (read-only)')->everyFifteenMinutes()->withoutOverlapping(30)->onOneServer();
Schedule::command(SyncMailcowDomains::class)->description('Reads the domains and mailboxes of every mailcow server (read-only)')->everyFifteenMinutes()->withoutOverlapping(30)->onOneServer();
Schedule::command(SyncCpanelAccounts::class)->description('Reads the accounts of every cPanel server (read-only)')->everyThirtyMinutes()->withoutOverlapping(30)->onOneServer();
Schedule::command(SyncSslOrders::class)->description('Reads every Openprovider SSL order again: status, dates, certificate, validation')->cron('41 * * * *')->withoutOverlapping(30)->onOneServer();
Schedule::command(ReadBalances::class)->description('Reads what is left with each registrar (the dashboard\'s «Registrar credit»; staff mailed when low)')->hourlyAt(7)->withoutOverlapping(30)->onOneServer();
Schedule::command(SyncCosts::class)->description('Reads the registrars\' costs for the TLDs (never a price)')->weeklyOn(1, '05:30')->withoutOverlapping()->onOneServer();
Schedule::command(GatewayLogPrune::class)->description('Deletes refused gateway requests that moved no money, after 90 days')->dailyAt('06:15')->withoutOverlapping()->onOneServer();
Schedule::command(RegistrarDebugLogPrune::class)->description('Deletes registrar debug log files (contacts\' details) after 14 days')->dailyAt('06:20')->withoutOverlapping()->onOneServer();
// The mail audit (docs/mail.md): mails stuck in sending or pending are marked failed, for staff to resend.
Schedule::command(MailSweep::class)->description('Marks mails stuck on their way out as failed')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
// EU VAT (PR G, docs/eu-vat.md): pending numbers until VIES answers, the others monthly. Nothing when EU VAT is off.
Schedule::command(VatRecheck::class)->description('Checks EU VAT numbers with VIES')->hourlyAt(17)->withoutOverlapping()->onOneServer();
