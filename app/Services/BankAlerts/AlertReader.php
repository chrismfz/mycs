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
 * - a credit whose reference contains a configured text (the card acquirer's settlements) is ignored.
 */
final class AlertReader
{
    /** Mails read per folder and run: a first read of thousands is spread over a few runs. */
    public const BATCH = 300;

    /** @param  array{folders: list<string>, authserv_id: string, arc_sealer: string, ignore: list<string>}  $settings */
    public function __construct(private readonly AlertMailbox $mailbox, private readonly array $settings) {}

    /** @return array{read: int, new: int, errors: list<string>} */
    public function run(): array
    {
        $summary = ['read' => 0, 'new' => 0, 'errors' => []];
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
        if (!is_array($cursor) || ($cursor['validity'] ?? null) !== $status['validity']) {
            // First sight of the folder (or it was made anew): what it holds now is history.
            $cursor = ['validity' => $status['validity'], 'last' => 0, 'history_until' => max(0, $status['next'] - 1)];
        }
        foreach ($this->mailbox->messages($folder, $cursor['last'] + 1, self::BATCH) as [$uid, $raw]) {
            $summary['read']++;
            if ($this->store($raw, $folder, $uid <= $cursor['history_until'])) {
                $summary['new']++;
            }
            $cursor['last'] = max($cursor['last'], $uid);
        }
        // Kept apart from the global settings (never in config('settings.*')), as CronJob keeps its markers.
        Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => BankMovement::class, 'settingable_id' => null],
            ['value' => json_encode($cursor), 'type' => 'string']);
    }

    /** One mail: stored once; true when it was new and is a bank's alert. */
    public function store(string $raw, string $folder, bool $history): bool
    {
        $alert = AlertParser::parse($raw, $this->settings['authserv_id'], $this->settings['arc_sealer'], (string) config('app.timezone'));
        if ($alert === null) {
            return false;
        }
        $messageKey = hash('sha256', $alert->messageId !== null && $alert->messageId !== '' ? 'mid:' . $alert->messageId : 'raw:' . $raw);
        $movement = BankMovement::query()->firstOrCreate(['message_key' => $messageKey], [
            'bank' => $alert->bank, 'direction' => $alert->direction, 'amount' => $alert->amount, 'balance' => $alert->balance,
            'reason' => $alert->reason, 'executed_at' => $alert->executedAt, 'message_id' => $alert->messageId !== null ? mb_substr($alert->messageId, 0, 255) : null,
            'folder' => $folder, 'authentic' => $alert->authentic, 'authenticity' => mb_substr($alert->authenticity, 0, 64),
            'status' => $this->status($alert, $history),
        ]);

        return $movement->wasRecentlyCreated;
    }

    private function status(BankAlert $alert, bool $history): string
    {
        if ($history) {
            return BankMovement::HISTORY;
        }
        if (!$alert->isCredit()) {
            return BankMovement::IGNORED; // a debit: for the balance only
        }
        foreach ($this->settings['ignore'] as $text) {
            if (mb_stripos($alert->reason, $text) !== false) {
                return BankMovement::IGNORED;
            }
        }

        return BankMovement::PENDING;
    }
}
