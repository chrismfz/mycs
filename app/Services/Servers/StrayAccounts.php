<?php

namespace App\Services\Servers;

use App\Contracts\ReadsAccounts;
use App\Models\Domain;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The rule for every server module (owner 2026-11-08): after each read of a server's accounts, a «check this» task in
 * Pending Module Actions for each account
 * - still on the server whose service ended in MyCS (terminated or cancelled): STRAY_ENDED;
 * - running with no service (not marked internal, not a template, not a reseller's own customer's): STRAY_UNMAPPED.
 *
 * One open task per server and account (subject «server id:remote id»), never one per read; it closes on its own when a
 * later read no longer finds that (the account gone, mapped, marked internal, its service active again). A difference an
 * open MyCS action explains (its suspend, unsuspend or terminate, failed or left to staff) is not a task, nor is one whose
 * service changed after the read began (the next read decides). One staff closed («Checked») is not opened again while
 * the account stays as it is; once that ends, a later return opens a new one. Only tasks: nothing is ever run on a server
 * from here (the cPanel reference account, service #4 on orion, is never terminated).
 *
 * A registrar sync's warning (what it did not apply: an expiry moved back, a contact handle that differs) is the same
 * kind of task for its domain: DOMAIN_CHECK.
 */
final class StrayAccounts
{
    /** MyCS's own actions on a service that, while open, explain an account still there (as CpanelAccount::differs). */
    private const EXPLAINS = ['suspend', 'unsuspend', 'terminate'];

    /** What a cleared row staff closed says (a later return opens a new task). */
    private const CLEARED = '; cleared since';

    /**
     * Turn a read's report into tasks: open the new ones, refresh the open ones, close the ones it no longer finds.
     *
     * @param  CarbonInterface|null  $readAt  when the read began: a service changed after it is not judged by it
     * @return array{opened: int, closed: int}
     */
    public static function check(Server $server, ReadsAccounts $reader, ?CarbonInterface $readAt = null): array
    {
        $readAt ??= now();
        $accounts = [];
        foreach ($reader->accounts($server) as $account) {
            $accounts[self::subject($server, $account->remoteId)] = $account;
        }
        $ids = array_values(array_unique(array_filter(array_map(fn (ServerAccount $a) => $a->serviceId, $accounts))));
        $services = Service::query()->whereKey($ids)->get(['id', 'status', 'updated_at'])->keyBy('id');
        $explained = ModuleAction::query()->open()->whereIn('service_id', $ids)->whereIn('action', self::EXPLAINS)
            ->distinct()->pluck('service_id')->flip();

        // subject => [action, service id, task]; and the undecided ones (their service changed during the read).
        $held = [];
        $undecided = [];
        foreach ($accounts as $subject => $account) {
            $service = $account->serviceId !== null ? $services->get($account->serviceId) : null;
            if ($service !== null) {
                if (!in_array($service->status, Service::ENDED_STATUSES, true) || isset($explained[$service->id])) {
                    continue;
                }
                if ($service->updated_at !== null && $service->updated_at->gt($readAt)) {
                    $undecided[$subject] = true;

                    continue;
                }
                $held[$subject] = [ModuleAction::STRAY_ENDED, $service->id, "{$server->name}: «{$account->label}» is still on the server"
                    . ($account->running ? '' : ' (suspended)') . ", its service #{$service->id} {$service->status} in MyCS. Check it: remove it on the "
                    . 'server by hand if it should go, or map it to the service it is now.'];
            } elseif ($account->serviceId === null && $account->running && !$account->internal) {
                $held[$subject] = [ModuleAction::STRAY_UNMAPPED, null, "{$server->name}: «{$account->label}» runs on the server with no service in MyCS. "
                    . 'Check it: map it to its service, mark it internal, or remove it on the server by hand.'];
            }
        }

        return DB::transaction(function () use ($server, $held, $undecided) {
            $now = now();
            $opened = 0;
            $closed = 0;
            $prefix = $server->id . ':';
            $open = ModuleAction::query()->open()->whereIn('action', ModuleAction::STRAY)->where('subject', 'like', $prefix . '%')
                ->orderBy('id')->get();
            $kept = [];
            foreach ($open as $row) {
                $subject = (string) $row->subject;
                if (isset($undecided[$subject])) {
                    $kept[$subject] = true;

                    continue;
                }
                [$action, $serviceId, $task] = $held[$subject] ?? [null, null, null];
                if ($action === $row->action && !isset($kept[$subject])) {
                    $kept[$subject] = true;
                    if ($row->last_error !== $task || $row->service_id !== $serviceId) {
                        $row->forceFill(['last_error' => $task, 'service_id' => $serviceId, 'last_attempt_at' => $now])->save();
                    }

                    continue;
                }
                $row->forceFill(['status' => ModuleAction::STATUS_RESOLVED, 'resolved_at' => $now,
                    'resolution' => match (true) {
                        $action === null => 'cleared: a later read no longer finds it',
                        $action === $row->action => 'cleared: the same check open twice',
                        default => 'cleared: now another check',
                    }])->save();
                $closed++;
            }
            // Staff's closed ones whose state ended: a later return is a new task.
            ModuleAction::query()->whereIn('action', ModuleAction::STRAY)->where('subject', 'like', $prefix . '%')
                ->where('status', ModuleAction::STATUS_RESOLVED)->where('resolution', ModuleAction::RESOLVED_BY_STAFF)->get()
                ->reject(fn (ModuleAction $row) => isset($undecided[(string) $row->subject]) || ($held[(string) $row->subject][0] ?? null) === $row->action)
                ->each(fn (ModuleAction $row) => $row->forceFill(['resolution' => ModuleAction::RESOLVED_BY_STAFF . self::CLEARED])->save());

            foreach ($held as $subject => [$action, $serviceId, $task]) {
                if (isset($kept[$subject]) || self::checkedByStaff((string) $subject, $action, ModuleAction::STRAY)) {
                    continue;
                }
                ModuleAction::query()->create(['service_id' => $serviceId, 'action' => $action, 'subject' => $subject,
                    'module' => $server->extension, 'status' => ModuleAction::STATUS_OPEN, 'attempts' => 0, 'last_attempt_at' => $now,
                    'given_up_at' => $now, 'last_error' => $task]);
                $opened++;
            }

            return ['opened' => $opened, 'closed' => $closed];
        });
    }

    /** check() after a module's read, under its lock: a failure here is reported, never the read's (its rows are written). */
    public static function afterRead(Server $server, ReadsAccounts $reader, ?CarbonInterface $readAt = null): void
    {
        try {
            self::check($server, $reader, $readAt);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A registrar sync's warning for staff (what it did not apply), or none: one open task per domain, its text the latest
     * warning; closed when a sync finds nothing to warn about. Not while the domain's own renewal, registration or transfer
     * is open (that row is staff's already). Called under the domain's registrar lock (DomainSyncService::sync).
     */
    public static function domainWarning(Domain $domain, ?string $warning, ?string $module = null): void
    {
        $explained = $warning !== null && ModuleAction::query()->open()->where('domain_id', $domain->id)
            ->whereIn('action', ModuleAction::DOMAIN_ACTIONS)->exists();
        $task = $warning === null || $explained ? null
            : Str::limit("{$domain->fqdn}: the registrar sync did not apply what it read: {$warning}. Check it at the registrar, then mark it checked.", 2000);
        DB::transaction(function () use ($domain, $task, $module) {
            $now = now();
            $open = ModuleAction::query()->open()->where('domain_id', $domain->id)->where('action', ModuleAction::DOMAIN_CHECK)->orderBy('id')->get();
            $keep = $task === null ? null : $open->first();
            foreach ($open as $row) {
                if ($row->is($keep)) {
                    if ($row->last_error !== $task) {
                        $row->forceFill(['last_error' => $task, 'last_attempt_at' => $now])->save();
                    }

                    continue;
                }
                $row->forceFill(['status' => ModuleAction::STATUS_RESOLVED, 'resolved_at' => $now, 'resolution' => 'cleared: a later sync no longer warns'])->save();
            }
            // Staff's closed one, for another warning or none now: a later warning is a new task.
            ModuleAction::query()->where('domain_id', $domain->id)->where('action', ModuleAction::DOMAIN_CHECK)
                ->where('status', ModuleAction::STATUS_RESOLVED)->where('resolution', ModuleAction::RESOLVED_BY_STAFF)
                ->where(fn ($q) => $task === null ? $q : $q->where('last_error', '!=', $task))
                ->update(['resolution' => ModuleAction::RESOLVED_BY_STAFF . self::CLEARED, 'updated_at' => $now]);
            if ($task === null || $keep !== null) {
                return;
            }
            $last = ModuleAction::query()->where('domain_id', $domain->id)->where('action', ModuleAction::DOMAIN_CHECK)->latest('id')->first();
            if ($last !== null && $last->resolution === ModuleAction::RESOLVED_BY_STAFF && $last->last_error === $task) {
                return; // checked by staff, and still the same
            }
            ModuleAction::query()->create(['domain_id' => $domain->id, 'action' => ModuleAction::DOMAIN_CHECK, 'module' => $module,
                'status' => ModuleAction::STATUS_OPEN, 'attempts' => 0, 'last_attempt_at' => $now, 'given_up_at' => $now, 'last_error' => $task]);
        });
    }

    /**
     * A task's key on its server: «server id:remote id» (module_actions.subject, 191 characters: a longer remote id by
     * its hash).
     */
    public static function subject(Server $server, string $remoteId): string
    {
        $subject = $server->id . ':' . $remoteId;

        return mb_strlen($subject) <= 191 ? $subject : $server->id . ':#' . sha1($remoteId);
    }

    /** The server and remote id a task's subject names (for the list). @return array{0: int, 1: string}|null */
    public static function parse(?string $subject): ?array
    {
        if ($subject === null || !preg_match('/^(\d+):(.+)$/s', $subject, $m)) {
            return null;
        }

        return [(int) $m[1], $m[2]];
    }

    /** The account's latest task is this check, closed by staff, and it has stayed so since. @param list<string> $actions */
    private static function checkedByStaff(string $subject, string $action, array $actions): bool
    {
        $last = ModuleAction::query()->whereIn('action', $actions)->where('subject', $subject)->latest('id')->first();

        return $last !== null && $last->action === $action && $last->resolution === ModuleAction::RESOLVED_BY_STAFF;
    }
}
