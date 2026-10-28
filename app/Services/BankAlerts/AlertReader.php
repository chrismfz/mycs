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
        try {
            $this->recheckAccounts();
        } catch (Throwable $e) {
            $summary['errors'][] = 'Our accounts: ' . $e->getMessage(); // never keeps the folders from being read (review of #491)
        }
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

    /**
     * «Our accounts» changed after alerts were read (owner 2026-10-28: the second Piraeus account), both ways:
     * - an account set as ours: its vouched-for movements, kept as «not one of our accounts», now count. History stays
     *   history, a debit ignored, what staff did stays; a credit staff have not seen to is theirs to see to, never matched
     *   (they were told to check it at the bank and may have recorded it by hand: adversarial review of #491);
     * - an account taken out (set by mistake): its movements no longer count, and a credit not yet matched is unverified.
     * The key follows, so the same mail read again is no second movement; a movement with no Message-ID cannot rebuild its
     * key and stays as it is (review of #491).
     */
    private function recheckAccounts(): void
    {
        foreach (array_keys(BankMovement::BANKS) as $bank) {
            $ours = array_map(fn ($a) => mb_strtoupper(trim((string) $a)), $this->settings['accounts'][$bank] ?? []);
            $isOurs = fn (?string $account) => $account !== null && in_array(mb_strtoupper(trim($account)), $ours, true);
            // The few accounts the bank's movements name, compared here as ours() does (a database's UPPER is not mb_strtoupper).
            $accounts = fn (bool $authentic) => BankMovement::query()->where('bank', $bank)->where('authentic', $authentic)
                ->when(!$authentic, fn ($q) => $q->whereIn('authenticity', ['not_our_account', BankMovement::TAKEN_OUT]))
                ->whereNotNull('account')->distinct()->pluck('account');

            $added = $accounts(false)->filter($isOurs)->values()->all();
            $removed = $accounts(true)->reject($isOurs)->values()->all();
            // One with no Message-ID only when it was ours before (its key never changed): round 2 of #491.
            BankMovement::query()->where('bank', $bank)->whereIn('account', $added)
                ->where(fn ($q) => $q->where('authenticity', BankMovement::TAKEN_OUT)
                    ->orWhere(fn ($q) => $q->where('authenticity', 'not_our_account')->whereNotNull('message_id')))
                ->lazyById()->each(fn (BankMovement $movement) => $this->recount($movement, true));
            BankMovement::query()->where('bank', $bank)->where('authentic', true)->whereIn('account', $removed)
                ->lazyById()->each(fn (BankMovement $movement) => $this->recount($movement, false));
        }
    }

    private function recount(BankMovement $movement, bool $ours): void
    {
        $changes = ['authentic' => $ours, 'authenticity' => $ours ? 'account_added_later' : BankMovement::TAKEN_OUT];
        if ($ours && $movement->status === BankMovement::UNVERIFIED) {
            $changes['status'] = array_filter($this->settings['ignore'], fn ($text) => mb_stripos($movement->reason, $text) !== false) !== []
                ? BankMovement::IGNORED : BankMovement::PENDING;
            $changes['match_note'] = 'Its account was set as ours later: see to it by hand';
        } elseif (!$ours && $movement->status === BankMovement::PENDING && $movement->invoice_id === null) {
            $changes['status'] = BankMovement::UNVERIFIED;
        }
        if ($movement->message_id !== null) {
            $key = hash('sha256', ($ours ? 'authentic|' : 'unverified|') . 'mid:' . $movement->message_id);
            if (!BankMovement::query()->where('message_key', $key)->whereKeyNot($movement->id)->exists()) {
                $changes['message_key'] = $key;
            } elseif ($ours) {
                return; // that mail's other copy is a movement already: this one stays as it is
            }
            // Taken out with an unvouched copy of it stored: it stops counting all the same, with its own key, which the
            // mail read again does not reach (it finds the copy's): round 2 of #491.
        } elseif ($ours && $movement->authenticity !== BankMovement::TAKEN_OUT) {
            return;
        }
        // Only as it was read: staff seeing to it meanwhile keep what they did (review of #491).
        BankMovement::query()->whereKey($movement->id)->where('status', $movement->status)->where('authentic', $movement->authentic)->update($changes);
    }

    /** Its account is one of ours for its bank (Bank transfer → «Our accounts»): none set, none is ours. */
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
