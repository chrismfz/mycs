<?php

namespace App\Services\Mcp;

use App\Models\EmailLog;
use App\Models\McpProposal;
use App\Models\ModuleAction;
use App\Models\Staff;
use App\Services\Mail\MailAudit;
use App\Services\Ops\NotFound;
use App\Services\Ops\Scrub;
use App\Services\Service\RetryModuleAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * What the MCP may only propose (docs/mcp.md): an agent asks; staff approve or reject in the admin within a day. A
 * proposal is checked when it is made and again, from what is stored, when it is approved: the approver's permission,
 * and the state (a module action still open and given up, a mail still there). Approved once: an atomic claim, so two
 * clicks never run it twice. Its result is kept.
 */
final class Proposals
{
    public const HOURS = 24;

    /** Pending proposals one member of staff may have at once: an agent cannot bury the queue (review R8). */
    public const PENDING_PER_PROPOSER = 30;

    /** An approved run not finished after this is taken as interrupted (review R2). */
    public const RUN_MINUTES = 60;

    public const RETRY_MODULE_ACTION = 'retry_module_action';

    public const RESOLVE_MODULE_ACTION = 'resolve_module_action';

    public const RESEND_MAIL = 'resend_mail';

    /** The role permission each type needs, of who proposes and of who approves. */
    public const PERMISSIONS = [
        self::RETRY_MODULE_ACTION => 'admin.services.update',
        self::RESOLVE_MODULE_ACTION => 'admin.services.update',
        self::RESEND_MAIL => 'admin.email_logs.resend',
    ];

    /** @param array<string, mixed> $payload */
    public static function propose(string $type, array $payload, Staff $staff): McpProposal
    {
        if (!$staff->hasPermission(self::permission($type))) {
            throw new InvalidArgumentException('Your role may not ask for this.');
        }
        [$payload, $summary] = self::check($type, $payload);
        // The same request, already waiting (whoever asked): one to approve. Who asks again follows it with `proposal`,
        // which shows what the caller may decide too (review R1).
        $open = McpProposal::query()->where('type', $type)->where('status', McpProposal::STATUS_PENDING)->where('expires_at', '>', now())
            ->get()->first(fn (McpProposal $proposal) => $proposal->payload == $payload);
        if ($open !== null) {
            return $open;
        }
        $pending = McpProposal::query()->where('proposed_by', $staff->id)->where('status', McpProposal::STATUS_PENDING)->where('expires_at', '>', now());
        if ($pending->count() >= self::PENDING_PER_PROPOSER) {
            throw new InvalidArgumentException('Too many of your proposals are waiting: staff decide those first.');
        }

        return McpProposal::query()->create(['type' => $type, 'payload' => $payload, 'summary' => $summary, 'proposed_by' => $staff->id,
            'status' => McpProposal::STATUS_PENDING, 'expires_at' => now()->addHours(self::HOURS)]);
    }

    /** Approve and run: once, by who may, if it still holds. */
    public static function approve(McpProposal $proposal, Staff $staff): McpProposal
    {
        if (!$staff->hasPermission(self::permission($proposal->type))) {
            throw new InvalidArgumentException('Your role may not approve this.');
        }
        $proposal = self::decide($proposal, McpProposal::STATUS_APPROVED, $staff)
            ?? throw new InvalidArgumentException('It is no longer waiting (decided, or expired).');
        try {
            [$payload] = self::check($proposal->type, $proposal->payload);
            $result = self::run($proposal->type, $payload, $staff);
            $proposal->update(['status' => McpProposal::STATUS_DONE, 'result' => mb_substr((string) Scrub::text($result), 0, 2000)]);
        } catch (Throwable $e) {
            report($e);
            // The agent reads it (`proposal`): masked as every tool's error (review R3).
            $proposal->update(['status' => McpProposal::STATUS_FAILED, 'result' => mb_substr((string) Scrub::text($e->getMessage()), 0, 2000)]);
        }

        return $proposal->refresh();
    }

    public static function reject(McpProposal $proposal, Staff $staff): void
    {
        self::decide($proposal, McpProposal::STATUS_REJECTED, $staff);
    }

    /**
     * The decision, once: the row locked, still pending and not expired, then saved through the model, so the audit
     * says who decided (review R4). Null: someone decided first, or it expired.
     */
    private static function decide(McpProposal $proposal, string $status, Staff $staff): ?McpProposal
    {
        return DB::transaction(function () use ($proposal, $status, $staff) {
            $locked = McpProposal::query()->whereKey($proposal->id)->lockForUpdate()->first();
            if ($locked === null || !$locked->isOpen()) {
                return null;
            }
            $locked->update(['status' => $status, 'decided_by' => $staff->id, 'decided_at' => now()]);

            return $locked;
        });
    }

    /**
     * The cron: pending past their day expire; a run approved but never finished (a timeout, a deploy) is failed with
     * its outcome unknown, for staff to check (review R2).
     */
    public static function expire(): int
    {
        $expired = McpProposal::query()->where('status', McpProposal::STATUS_PENDING)->where('expires_at', '<=', now())->get()
            ->each(fn (McpProposal $proposal) => $proposal->update(['status' => McpProposal::STATUS_EXPIRED]))->count();
        $interrupted = McpProposal::query()->where('status', McpProposal::STATUS_APPROVED)->where('decided_at', '<', now()->subMinutes(self::RUN_MINUTES))->get()
            ->each(fn (McpProposal $proposal) => $proposal->update(['status' => McpProposal::STATUS_FAILED,
                'result' => 'Interrupted while running: its outcome is unknown. Check what it was to change before proposing it again.']))->count();

        return $expired + $interrupted;
    }

    /**
     * The proposals a member of staff may follow: their own, and those they may decide (the same as could ask them).
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function visibleTo(Builder $query, ?Staff $staff): Builder
    {
        $types = array_keys(array_filter(self::PERMISSIONS, fn (string $permission) => $staff?->hasPermission($permission) ?? false));

        return $query->where(fn (Builder $query) => $query->where('proposed_by', $staff === null ? 0 : $staff->id)->orWhereIn('type', $types));
    }

    public static function permission(string $type): string
    {
        return self::PERMISSIONS[$type] ?? throw new InvalidArgumentException("Unknown proposal {$type}.");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: string} the payload kept, and what staff are asked
     */
    private static function check(string $type, array $payload): array
    {
        return match ($type) {
            self::RETRY_MODULE_ACTION, self::RESOLVE_MODULE_ACTION => self::checkModuleAction($type, $payload),
            self::RESEND_MAIL => self::checkMail($payload),
            default => throw new InvalidArgumentException("Unknown proposal {$type}."),
        };
    }

    /** @param array<string, mixed> $payload */
    private static function checkModuleAction(string $type, array $payload): array
    {
        $action = ModuleAction::query()->with('service.product')->find((int) ($payload['module_action_id'] ?? 0))
            ?? throw new NotFound('No such module action.');
        if ($action->status !== ModuleAction::STATUS_OPEN) {
            throw new InvalidArgumentException("Module action #{$action->id} is {$action->status}, not open.");
        }
        // A domain's renewal (D5b-2, adversarial ADV-10): no queue retries it, so any time; said as what it does.
        if ($action->action === ModuleAction::DOMAIN_RENEW) {
            $what = 'the paid renewal of ' . ($action->domain->fqdn ?? "domain #{$action->domain_id}") . ' (invoice #' . ($action->invoiceItem->invoice_id ?? '?') . ')';

            return [['module_action_id' => $action->id], $type === self::RETRY_MODULE_ACTION
                ? "Retry {$what} at its registrar now: it reads the registrar and charges the years it lacks, unless a charge is unconfirmed."
                : "Mark {$what} renewed by hand: applied only if the registrar shows it (a manual registrar: its expiry moves to the end of the paid period)."];
        }
        // A domain ordered (D8b-2): no queue retries it either.
        if (in_array($action->action, [ModuleAction::DOMAIN_REGISTER, ModuleAction::DOMAIN_TRANSFER], true)) {
            $what = ($action->action === ModuleAction::DOMAIN_REGISTER ? 'the registration of ' : 'the transfer of ') . ($action->domain->fqdn ?? "domain #{$action->domain_id}");

            return [['module_action_id' => $action->id], $type === self::RETRY_MODULE_ACTION
                ? "Send {$what} to its registrar now (charged to our account there), unless a charge is unconfirmed."
                : "Mark {$what} done by hand at the registrar: the sync takes it on and its payment counts as used."];
        }
        // A retry only once the automatic ones are over, as the admin's Retry: before that it would race the queue.
        if ($type === self::RETRY_MODULE_ACTION && $action->given_up_at === null) {
            throw new InvalidArgumentException("Module action #{$action->id} is still being retried by the queue.");
        }
        $what = "{$action->action} on service #{$action->service_id} (" . ($action->service->product->name ?? '?') . ')';

        return [['module_action_id' => $action->id], $type === self::RETRY_MODULE_ACTION
            ? "Retry {$what} on its server now, as the admin's Retry (and its mail)."
            : "Mark {$what} resolved without running anything (fixed by hand on the server)."];
    }

    /** @param array<string, mixed> $payload */
    private static function checkMail(array $payload): array
    {
        $log = EmailLog::query()->find((int) ($payload['email_log_id'] ?? 0)) ?? throw new NotFound('No such mail.');
        $to = trim((string) ($payload['to'] ?? '')) ?: $log->to;
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('«to» is not an email address.');
        }
        $copies = (bool) ($payload['with_copies'] ?? false);

        // The addresses whole, the subject shortened: the summary fits its column (review R7).
        return [['email_log_id' => $log->id, 'to' => $to, 'with_copies' => $copies], mb_substr(
            "Resend mail #{$log->id} «" . mb_strimwidth($log->subject, 0, 80, '…') . "» to {$to}" . ($to !== $log->to ? " (it went to {$log->to})" : '')
            . ($copies ? ', with its Cc/Bcc.' : '.'), 0, 500)];
    }

    /** @param array<string, mixed> $payload */
    private static function run(string $type, array $payload, Staff $staff): string
    {
        switch ($type) {
            case self::RETRY_MODULE_ACTION:
                $action = ModuleAction::query()->findOrFail($payload['module_action_id']);

                return RetryModuleAction::run($action) ? 'Ran on the server.' : 'Nothing to do: the service moved on (mark it resolved if the account is right).';
            case self::RESOLVE_MODULE_ACTION:
                ModuleAction::query()->findOrFail($payload['module_action_id'])->markResolved($staff);

                return 'Marked resolved.';
            case self::RESEND_MAIL:
                $log = MailAudit::resend(EmailLog::query()->findOrFail($payload['email_log_id']), $payload['to'], (bool) $payload['with_copies'], $staff);

                return "Queued as mail #{$log->id}.";
        }

        throw new InvalidArgumentException("Unknown proposal {$type}.");
    }
}
