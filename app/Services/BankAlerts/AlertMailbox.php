<?php

namespace App\Services\BankAlerts;

/** The seam over the alerts mailbox: the reader and its tests talk to this; ImapAlertMailbox is the real one. */
interface AlertMailbox
{
    /** The folder's UIDVALIDITY and the next UID it will give (what is below it is already there). */
    public function status(string $folder): array;

    /**
     * Its messages from UID $from on, oldest first, at most $limit, each as [uid, raw RFC 822], never marked read.
     *
     * @return iterable<array{0: int, 1: string}>
     */
    public function messages(string $folder, int $from, int $limit): iterable;
}
