<?php

namespace App\Support\Domains;

/**
 * The registrar transactions of this process (a singleton), as drivers tell them: each command with its clTRID, the
 * registrar's svTRID and result code. A service marks before a call and keeps on its log what came after (since()).
 * Bounded: a long worker never grows it without end.
 */
final class RegistrarTransactions
{
    private const KEEP = 200;

    /** @var array<int, array<string, mixed>> sequence => transaction */
    private array $all = [];

    private int $next = 0;

    /** @param  array<string, mixed>  $transaction */
    public function record(array $transaction): void
    {
        $this->all[$this->next++] = $transaction;
        if (count($this->all) > self::KEEP) {
            $this->all = array_slice($this->all, -self::KEEP, preserve_keys: true);
        }
    }

    public function mark(): int
    {
        return $this->next;
    }

    /**
     * The transactions since $mark (at most 50: a log is not a trace), null when none.
     *
     * @return list<array<string, mixed>>|null
     */
    public function since(int $mark): ?array
    {
        $since = array_values(array_filter($this->all, fn (int $sequence) => $sequence >= $mark, ARRAY_FILTER_USE_KEY));

        return $since === [] ? null : array_slice($since, -50);
    }
}
