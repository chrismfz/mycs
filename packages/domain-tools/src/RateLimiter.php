<?php

namespace Nixpal\DomainTools;

/**
 * At most $max hits per key in a sliding window of $window seconds. The store keeps the
 * hits (in Blesta: a table, purged by the plugin's cron).
 */
final class RateLimiter
{
    /** @var RateLimitStore */
    private $store;

    public function __construct(RateLimitStore $store)
    {
        $this->store = $store;
    }

    /** Records a hit and says whether it is within the limit (a refused hit is not recorded). */
    public function attempt($key, $max, $window, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        if ($max <= 0) {
            return true;
        }
        if ($this->store->count((string) $key, $now - (int) $window) >= $max) {
            return false;
        }
        $this->store->add((string) $key, $now);

        return true;
    }
}
