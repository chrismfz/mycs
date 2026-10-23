<?php

namespace App\Console\Commands;

use App\Models\TicketDepartment;
use App\Models\TicketMailImport;
use App\Models\TicketPollRun;
use App\Models\TicketQuarantine;
use App\Support\Tickets\InboundOutcome;
use App\Support\Tickets\InboundRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\InboundEmail;
use Throwable;

/**
 * Reads the mailbox of each department with polling on (docs/tickets.md A2) and routes each unseen mail
 * (InboundRouter). A mail is marked read only once it is in a ticket or in the quarantine (or skipped as seen before or
 * blocked); one that fails stays unread for the next run, and never stops the others. Every run is recorded
 * (ticket_poll_runs) for the health check.
 */
class FetchTicketMail extends Command
{
    protected $signature = 'app:fetch-emails {--department= : One department (its id)}';

    protected $description = 'Read the departments\' mailboxes into tickets (docs/tickets.md)';

    /** A mail that failed this many runs is kept in the quarantine with its error, never left to hide newer mail. */
    public const ATTEMPTS = 3;

    public function handle(Mailbox $mailbox, InboundRouter $router): int
    {
        Config::set('audit.console', true);
        // A 40 MB mail is parsed at a few times its size; an out-of-memory cannot be caught and would stop every mailbox.
        if ($this->bytes((string) ini_get('memory_limit')) < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }
        $departments = TicketDepartment::query()->where('polling', true)
            ->when($this->option('department'), fn ($query, $id) => $query->whereKey((int) $id))->get()
            ->filter(fn (TicketDepartment $department) => $department->hasMailbox());

        foreach ($departments as $department) {
            $run = TicketPollRun::query()->create(['department_id' => $department->id, 'started_at' => now()]);
            $counts = [InboundOutcome::THREADED => 0, InboundOutcome::OPENED => 0, InboundOutcome::QUARANTINED => 0, 'skipped' => 0];
            $errors = [];

            $summary = $mailbox->poll($department->mailboxSettings(), function (?InboundEmail $email, string $raw) use ($router, $department, &$counts, &$errors) {
                try {
                    $outcome = $router->route($department, $email, $raw);
                } catch (Throwable $e) {
                    Log::error('Ticket mail: a message failed', ['department' => $department->id, 'error' => $e->getMessage()]);
                    $errors[] = mb_substr($e->getMessage(), 0, 300);
                    $key = 'ticket-mail-attempts:' . $department->id . ':' . hash('sha256', $raw);
                    $attempts = (int) Cache::get($key, 0) + 1;
                    Cache::put($key, $attempts, now()->addDays(2));
                    if ($attempts < self::ATTEMPTS) {
                        return false;
                    }
                    try {
                        $outcome = $router->failed($department, $email, $raw, $e->getMessage());
                        Cache::forget($key);
                    } catch (Throwable) {
                        return false;
                    }
                }
                TicketMailImport::record($department, $email, $outcome);
                $kind = isset($counts[$outcome->kind]) ? $outcome->kind : 'skipped';
                $counts[$kind]++;

                return true;
            });

            $run->update(['finished_at' => now(), 'connected' => $summary->connected, 'fetched' => $summary->fetched,
                'threaded' => $counts[InboundOutcome::THREADED], 'opened' => $counts[InboundOutcome::OPENED],
                'quarantined' => $counts[InboundOutcome::QUARANTINED], 'skipped' => $counts['skipped'],
                'errors' => array_merge($summary->errors, $errors) ?: null]);
            $this->line("{$department->name}: {$summary->fetched} read, {$counts[InboundOutcome::OPENED]} new, {$counts[InboundOutcome::THREADED]} replies, "
                . "{$counts[InboundOutcome::QUARANTINED]} quarantined" . ($run->errors ? ', ' . count($run->errors) . ' errors' : ''));
        }

        // The import log as long as the quarantine keeps its mail: then senders and subjects of junk go too (review of #406).
        TicketMailImport::query()->where('created_at', '<', now()->subDays(90))->delete();
        // A month of runs is enough to tell the health of the polling.
        TicketPollRun::query()->where('started_at', '<', now()->subDays(30))->delete();
        // Handled quarantine (and its raw mail) is kept 90 days; pending stays until staff decide.
        TicketQuarantine::query()->where('status', '!=', TicketQuarantine::PENDING)->where('handled_at', '<', now()->subDays(90))
            ->each(fn (TicketQuarantine $quarantine) => $quarantine->delete());

        return self::SUCCESS;
    }

    private function bytes(string $limit): int
    {
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        $value = (int) $limit;

        return match (strtolower(substr(trim($limit), -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
