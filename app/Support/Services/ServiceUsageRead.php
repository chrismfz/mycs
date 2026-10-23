<?php

namespace App\Support\Services;

/**
 * An account's usage as its server tells it, in MB (a limit null: none). A reseller's: its accounts', with how many
 * it has and may have. Its IP there too, when the server tells it.
 */
final class ServiceUsageRead
{
    public function __construct(
        public readonly ?int $diskUsedMb = null,
        public readonly ?int $diskLimitMb = null,
        public readonly ?int $bandwidthUsedMb = null,
        public readonly ?int $bandwidthLimitMb = null,
        public readonly ?int $accounts = null,
        public readonly ?int $accountsLimit = null,
        /** What its accounts are: a reseller's hosting accounts, or mailboxes (mailcow). */
        public readonly string $accountsKind = 'accounts',
        /** The IP the server says the account has (cPanel's listaccts), when it tells one. */
        public readonly ?string $ip = null,
    ) {}
}
