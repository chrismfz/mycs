<?php

namespace Nixpal\DomainTools\Whois;

interface SocketConnector
{
    /** Sends $query to $host:43 and returns the answer (at most $max bytes), or null. */
    public function query($host, $query, $timeout = 8, $max = 65536);
}
