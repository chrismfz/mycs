<?php

namespace App\Services\Import;

use App\Models\TicketBlockedSender;
use PDO;
use PDOException;
use Throwable;

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
        $odd = 0;
        foreach ($rows as $row) {
            $value = trim((string) $row['content']);
            if (strtolower((string) $row['type']) !== 'sender') {
                $other++;
                $counts['skipped']++;

                continue;
            }
            // An address, or a domain as «@example.com» / «*@example.com»: anything else (no «@», no domain after it) blocks nothing
            // that WHMCS matched and is said, never aborts the import (ADV-562-1; a bare word would have become a whole-domain block).
            if (preg_match('/^[^@\s]*@[^@\s]+$/', $value) !== 1 || ($value[0] !== '@' && $value[0] !== '*' && str_ends_with($value, '@'))) {
                $odd++;
                $counts['skipped']++;

                continue;
            }
            try {
                $sender = TicketBlockedSender::query()->firstOrNew(['value' => TicketBlockedSender::normalise($value)]);
            } catch (Throwable) {
                $odd++;
                $counts['skipped']++;

                continue;
            }
            if (!$sender->exists) {
                $sender->forceFill(['note' => 'WHMCS spam filter'])->save();
                $counts['senders']++;
            }
        }
        if ($odd > 0) {
            $notes[] = "{$odd} sender filter(s) are not an address or a domain with an «@»: not carried.";
        }
        if ($other > 0) {
            $notes[] = "{$other} spam filter(s) on a subject or a phrase have no counterpart here: not carried.";
        }

        return ['counts' => $counts, 'notes' => $notes];
    }
}
