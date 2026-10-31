<?php

namespace App\Services\Import;

use App\Models\TicketBlockedSender;
use PDO;
use PDOException;

/**
 * WHMCS's ticket spam filters (`tblticketspamfilters`): a sender filter is a blocked sender (an address, or a whole domain
 * as `@example.com`); a subject or phrase filter has no counterpart here and is said. The same sender twice is one.
 */
final class WhmcsSpamFilterImport
{
    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        $counts = ['senders' => 0, 'skipped' => 0];
        $notes = [];
        try {
            $rows = $this->whmcs->query('SELECT type, content FROM tblticketspamfilters ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return ['counts' => $counts, 'notes' => $notes];
        }
        $other = 0;
        foreach ($rows as $row) {
            $value = trim((string) $row['content']);
            if (strtolower((string) $row['type']) !== 'sender' || $value === '') {
                $other += strtolower((string) $row['type']) !== 'sender' ? 1 : 0;
                $counts['skipped']++;

                continue;
            }
            $sender = TicketBlockedSender::query()->firstOrNew(['value' => TicketBlockedSender::normalise($value)]);
            if (!$sender->exists) {
                $sender->forceFill(['note' => 'WHMCS spam filter'])->save();
                $counts['senders']++;
            }
        }
        if ($other > 0) {
            $notes[] = "{$other} spam filter(s) on a subject or a phrase have no counterpart here: not carried.";
        }

        return ['counts' => $counts, 'notes' => $notes];
    }
}
