<?php

namespace Nixpal\Licensing;

interface LicenseRepository
{
    /** @return License|null */
    public function findByKey($licenseKey);

    /**
     * Record a check: last_access only. Never writes status or bindings, so a
     * check cannot undo a staff change made while it ran.
     *
     * @param  int  $now  unix time
     */
    public function touch(License $license, $now);

    /**
     * First bind (or bind after a reissue): store the license's IP/domain/
     * directory and status Active, but only if it is still unbound or Reissued and
     * not Suspended/Expired meanwhile (a check must never undo staff's suspend).
     *
     * @param  int  $now  unix time
     * @return bool false when another request bound it first (nothing written)
     */
    public function bind(License $license, $now);

    /**
     * Append to the access log (the WHMCS "License Access Logs").
     *
     * @param  License|null  $license  null for unknown keys
     */
    public function log($license, $domain, $ip, $directory, $message);
}
