<?php

namespace Nixpal\DomainTools;

interface RateLimitStore
{
    /** Hits of $key at or after $since (unix time). */
    public function count($key, $since);

    /** Records a hit of $key at $time (unix time). */
    public function add($key, $time);
}
