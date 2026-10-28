<?php

namespace App\Services\BankAlerts;

use App\Models\BankMovement;
use App\Models\Setting;
use MyCS\BankAlerts\AlertParser;
use MyCS\BankAlerts\BankAlert;
use Throwable;

/**
 * The banks' alert emails into bank_movements (Payments tracking, owner 2026-10-24), the mailbox left as it is:
 * - each folder from the UID after the last one read (its UIDVALIDITY changed: from the start, nothing read twice);
 * - what a folder already held the first time it is read is history: it counts for the balances, never as a new
 *   payment for staff or the matching;
 * - one mail is one movement wherever it is (its Message-ID): moved from INBOX to another folder, it is not read again;
 * - a credit whose reference contains a configured text (the card acquirer's settlements) is ignored;
 * - an alert counts (authentic) only when our mail server vouches the bank sent it AND it is about one of our accounts
 *   (a genuine alert about someone else's account, sent on to us, is no payment of ours); one that does not is
 *   «unverified»: shown to staff, never in the balances or the matching;
 * - one mail that cannot be read or stored is said and passed: it never stops the folder.
 */
final class AlertReader
{
    /** Mails read per folder and run: a first read of thousands is spread over a few runs. */
    public const BATCH = 300;

    /** Runs a mail that fails for another cause than its own data is tried in, before it is passed with an error. */
    public const ATTEMPTS = 5;

    /** @param  array{folders: list<string>, authserv_id: string, arc_sealer: string, ignore: list<string>, accounts?: array<string, list<string>>}  $settings */
    public function __construct(private readonly AlertMailbox $mailbox, private readonly array $settings) {}

    /** @return array{read: int, new: int, errors: list<string>} */
    public function run(): array
    {
        $summary = ['read' => 0, 'new' => 0, 'errors' => []];
        $this->recheckAccounts();
        foreach ($this->settings['folders'] as $folder) {
            try {
                $this->folder($folder, $summary);
            } catch (Throwable $e) {
                $summary['errors'][] = "{$folder}: " . $e->getMessage();
            }
        }

        return $summary;
    }

    private function folder(string $folder, array &$summary): void
    {
        $status = $this->mailbox->status($folder);
        $key = 'bank_alerts.cursor.' . $folder;
        $cursor = json_decode((string) Setting::query()->where('key', $key)->where('settingable_type', BankMovement::class)->value('value'), true);
        if (!is_array($cursor)) {
            // First sight of the folder: what it holds now is history.
            $cursor = ['validity' => $status['validity'], 'last' => 0, 'history_until' => max(0, $status['next'] - 1)];
        } elseif (($cursor['validity'] ?? null) !== $status['validity']) {
            // The folder was made anew (an index rebuilt, a restore): all of it again, as new. What was read is known by
            // its Message-ID; a credit not read yet must not become history (review of #450).
            $cursor = ['validity' => $status['validity'], 'last' => 0, 'history_until' => 0];
        }
        $save = function () use ($key, &$cursor) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => BankMovement::class, 'settingable_id' => null],
                ['value' => json_encode($cursor), 'type' => 'string']);
        };
        foreach ($this->mailbox->messages($folder, $cursor['last'] + 1, self::BATCH) as [$uid, $raw]) {
            $summary['read']++;
            try {
                if ($this->store($raw, $folder, $uid <= $cursor['history_until'])) {
                    $summary['new']++;
                }
                unset($cursor['failing']);
            } catch (Throwable $e) {
                report($e);
                $summary['errors'][] = "{$folder} UID {$uid}: " . mb_substr($e->getMessage(), 0, 300);
                // The mail's own data (a value out of range): passed at once, it never stops the folder (reviews of #450).
                // Anything else (a deadlock, the database gone): tried again next run from this mail, so a real alert is
                // never lost to a passing error; passed only after ATTEMPTS runs (adversarial round 2 of #450).
                $attempts = (($cursor['failing']['uid'] ?? null) === $uid ? (int) $cursor['failing']['attempts'] : 0) + 1;
                if (!self::mailAtFault($e) && $attempts < self::ATTEMPTS) {
                    $cursor['failing'] = ['uid' => $uid, 'attempts' => $attempts];
                    $save();

                    return;
                }
                unset($cursor['failing']);
            }
            $cursor['last'] = max($cursor['last'], $uid);
            $save(); // kept as it goes: a run cut short resumes after the last mail done
        }
        $save();
    }

    /** One mail: stored once; true when it was new and is a bank's alert. */
    public function store(string $raw, string $folder, bool $history): bool
    {
        $alert = AlertParser::parse($raw, $this->settings['authserv_id'], $this->settings['arc_sealer'], (string) config('app.timezone'));
        if ($alert === null) {
            return false;
        }
        $authentic = $alert->authentic && $this->ours($alert);
        $authenticity = $alert->authentic && !$authentic ? 'not_our_account' : $alert->authenticity;
        // One mail once, wherever it is; a vouched-for one never collides with an unvouched copy of its Message-ID (a
        // forged mail sent first must not suppress the bank's own: adversarial review of #450).
        $messageKey = hash('sha256', ($authentic ? 'authentic|' : 'unverified|')
            . ($alert->messageId !== null && $alert->messageId !== '' ? 'mid:' . $alert->messageId : 'raw:' . $raw));
        $movement = BankMovement::query()->firstOrCreate(['message_key' => $messageKey], [
            'bank' => $alert->bank, 'direction' => $alert->direction, 'amount' => $alert->amount, 'balance' => $alert->balance,
            'reason' => $alert->reason, 'executed_at' => $alert->executedAt, 'message_id' => $alert->messageId !== null ? mb_substr($alert->messageId, 0, 255) : null,
            'folder' => $folder, 'account' => $alert->account !== null ? mb_substr($alert->account, 0, 100) : null,
            'authentic' => $authentic, 'authenticity' => mb_substr($authenticity, 0, 64),
            'status' => $this->status($alert, $authentic, $history),
        ]);

        return $movement->wasRecentlyCreated;
    }

    /** A database refusal of the mail's own data (SQLSTATE class 22: out of range, invalid date, too long). */
    private static function mailAtFault(Throwable $e): bool
    {
        return preg_match('/SQLSTATE\[22[0-9A-Z]{3}\]/', $e->getMessage()) === 1;
    }

    /** Its account is one of ours for its bank (Bank transfer → «Our accounts»): none set, none is ours. */
    /**
     * An account set as ours after its alerts were read (owner 2026-10-28: the second Piraeus account): its vouched-for
     * movements, kept as «not one of our accounts», now count. As if read now: history stays history, a debit ignored, a
     * credit staff have not seen to is for them (or ignored by the configured texts); what staff did stays. Its key
     * becomes a vouched-for one's, so the same mail read again is not a second movement.
     */
    private function recheckAccounts(): void
    {
        foreach ($this->settings['accounts'] ?? [] as $bank => $accounts) {
            $ours = array_map(fn ($a) => mb_strtoupper(trim((string) $a)), $accounts);
            BankMovement::query()->where('bank', $bank)->where('authenticity', 'not_our_account')->whereNotNull('account')
                ->lazyById()->each(function (BankMovement $movement) use ($ours) {
                    if (!in_array(mb_strtoupper(trim((string) $movement->account)), $ours, true)) {
                        return;
                    }
                    $status = $movement->status;
                    if ($status === BankMovement::UNVERIFIED) {
                        $status = array_filter($this->settings['ignore'], fn ($text) => mb_stripos($movement->reason, $text) !== false) !== []
                            ? BankMovement::IGNORED : BankMovement::PENDING;
                    }
                    $key = $movement->message_id !== null && mb_strlen($movement->message_id) < 255
                        ? hash('sha256', 'authentic|mid:' . $movement->message_id) : $movement->message_key;
                    if ($key !== $movement->message_key && BankMovement::query()->where('message_key', $key)->exists()) {
                        return; // the bank's own copy is already a movement: this one stays as it is
                    }
                    $movement->update(['authentic' => true, 'authenticity' => 'account_added_later', 'status' => $status, 'message_key' => $key]);
                });
        }
    }

    private function ours(BankAlert $alert): bool
    {
        $ours = array_map(fn ($a) => mb_strtoupper(trim((string) $a)), $this->settings['accounts'][$alert->bank] ?? []);

        return $alert->account !== null && in_array(mb_strtoupper(trim($alert->account)), $ours, true);
    }

    private function status(BankAlert $alert, bool $authentic, bool $history): string
    {
        if ($history) {
            return BankMovement::HISTORY;
        }
        if (!$alert->isCredit()) {
            return BankMovement::IGNORED; // a debit: for the balance only
        }
        if (!$authentic) {
            return BankMovement::UNVERIFIED;
        }
        foreach ($this->settings['ignore'] as $text) {
            if (mb_stripos($alert->reason, $text) !== false) {
                return BankMovement::IGNORED;
            }
        }

        return BankMovement::PENDING;
    }
}
