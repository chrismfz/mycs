<?php

namespace Nixpal\Licensing\Tests;

use Nixpal\Licensing\License;
use Nixpal\Licensing\LicenseRepository;

final class InMemoryRepository implements LicenseRepository
{
    /** @var License[] */
    public $licenses = [];

    /** @var array[] */
    public $logs = [];

    /** @var int */
    public $saves = 0;

    /** @var callable|null runs inside bind(), before the check (simulates a concurrent request) */
    public $beforeBind;

    public function add(License $license)
    {
        $this->licenses[$license->licenseKey] = $license;
    }

    public function findByKey($licenseKey)
    {
        return isset($this->licenses[$licenseKey]) ? clone $this->licenses[$licenseKey] : null;
    }

    public function touch(License $license, $now)
    {
        $this->saves++;
        $this->licenses[$license->licenseKey]->lastAccess = $license->lastAccess;
    }

    public function bind(License $license, $now)
    {
        if ($this->beforeBind) {
            call_user_func($this->beforeBind, $this);
        }
        $stored = $this->licenses[$license->licenseKey];
        if (!in_array($stored->status, [License::ACTIVE, License::REISSUED], true)
            || ($stored->validIps() !== [] && $stored->status !== License::REISSUED)
        ) {
            return false;
        }
        $this->saves++;
        $stored->status = $license->status;
        $stored->validIp = $license->validIp;
        $stored->validDomain = $license->validDomain;
        $stored->validDirectory = $license->validDirectory;
        $stored->lastAccess = $license->lastAccess;

        return true;
    }

    public function log($license, $domain, $ip, $directory, $message)
    {
        $this->logs[] = [
            'license' => $license ? $license->id : null,
            'domain' => $domain,
            'ip' => $ip,
            'dir' => $directory,
            'message' => $message,
        ];
    }
}
