<?php

namespace Nixpal\Mailcow;

/**
 * What suspend and terminate do (package settings), as in the WHMCS module:
 *  - suspend locks the domain admin only (mailboxes keep working), and only when the
 *    package enables it;
 *  - terminate is lock_only (safe, default) or delete_all (mailboxes, aliases, admin,
 *    domain: irreversible);
 *  - a domain admin that manages several domains is not locked unless forced, because
 *    the lock would hit every one of them.
 */
final class Policy
{
    const LOCK_ONLY = 'lock_only';

    const DELETE_ALL = 'delete_all';

    /** @var bool */
    public $lockOnSuspend = true;

    /** @var bool */
    public $forceLockMultiDomain = false;

    /** @var string */
    public $terminateMode = self::LOCK_ONLY;

    public static function fromMeta(array $meta)
    {
        $policy = new self;
        $policy->lockOnSuspend = ($meta['lock_on_suspend'] ?? 'true') === 'true';
        $policy->forceLockMultiDomain = ($meta['force_lock_multi'] ?? 'false') === 'true';
        $policy->terminateMode = ($meta['terminate_mode'] ?? '') === self::DELETE_ALL ? self::DELETE_ALL : self::LOCK_ONLY;

        return $policy;
    }

    /**
     * Whether to lock the domain admin on suspend.
     *
     * @param  string[]  $adminDomains  the domains the admin manages
     */
    public function lockOnSuspend(array $adminDomains)
    {
        return $this->lockOnSuspend && $this->mayLock($adminDomains);
    }

    /** Whether lock_only termination may lock the admin. */
    public function lockOnTerminate(array $adminDomains)
    {
        return $this->terminateMode === self::LOCK_ONLY && $this->mayLock($adminDomains);
    }

    public function deletesOnTerminate()
    {
        return $this->terminateMode === self::DELETE_ALL;
    }

    /**
     * Unsuspend reactivates the admin only where suspend would have locked it, so it never
     * unlocks an admin locked for another service or by hand.
     */
    public function unlockOnUnsuspend(array $adminDomains)
    {
        return $this->lockOnSuspend($adminDomains);
    }

    /**
     * delete_all deletes the domain admin only when it manages nothing but this domain;
     * otherwise the domain is dropped from its list and the admin stays for the others.
     */
    public static function adminIsOnlyFor($domain, array $adminDomains)
    {
        $others = array_diff($adminDomains, [strtolower((string) $domain)]);

        return $others === [];
    }

    private function mayLock(array $adminDomains)
    {
        return $this->forceLockMultiDomain || count($adminDomains) <= 1;
    }
}
