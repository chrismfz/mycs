<?php

namespace App\Services\Mcp;

use App\Models\EmailLog;
use App\Models\McpProposal;
use App\Models\ModuleAction;
use App\Models\Staff;
use App\Services\Mail\MailAudit;
use App\Services\Ops\NotFound;
use App\Services\Service\RetryModuleAction;
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
        $open = McpProposal::query()->where('type', $type)->where('status', McpProposal::STATUS_PENDING)
            ->where('expires_at', '>', now())->get()->first(fn (McpProposal $proposal) => $proposal->payload == $payload);
        if ($open !== null) {
            return $open; // the same request, already waiting: one to approve
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
        $claimed = McpProposal::query()->whereKey($proposal->id)->where('status', McpProposal::STATUS_PENDING)->where('expires_at', '>', now())
            ->update(['status' => McpProposal::STATUS_APPROVED, 'decided_by' => $staff->id, 'decided_at' => now()]);
        if ($claimed !== 1) {
            throw new InvalidArgumentException('It is no longer waiting (decided, or expired).');
        }
        try {
            [$payload] = self::check($proposal->type, $proposal->payload);
            $result = self::run($proposal->type, $payload, $staff);
            $proposal->forceFill(['status' => McpProposal::STATUS_DONE, 'result' => mb_substr($result, 0, 2000)])->save();
        } catch (Throwable $e) {
            report($e);
            $proposal->forceFill(['status' => McpProposal::STATUS_FAILED, 'result' => mb_substr($e->getMessage(), 0, 2000)])->save();
        }

        return $proposal->refresh();
    }

    public static function reject(McpProposal $proposal, Staff $staff): void
    {
        McpProposal::query()->whereKey($proposal->id)->where('status', McpProposal::STATUS_PENDING)
            ->update(['status' => McpProposal::STATUS_REJECTED, 'decided_by' => $staff->id, 'decided_at' => now()]);
    }

    /** Pending past their day: expired (the cron). */
    public static function expire(): int
    {
        return McpProposal::query()->where('status', McpProposal::STATUS_PENDING)->where('expires_at', '<=', now())->update(['status' => McpProposal::STATUS_EXPIRED]);
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

        return [['email_log_id' => $log->id, 'to' => $to, 'with_copies' => $copies],
            "Resend mail #{$log->id} «{$log->subject}» to {$to}" . ($to !== $log->to ? " (it went to {$log->to})" : '') . ($copies ? ', with its Cc/Bcc.' : '.')];
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
