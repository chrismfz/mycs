<?php

namespace Nixpal\Licensing;

/**
 * In-process replacement for zlic.php + WHMCS verify.php.
 *
 * Output follows tests/fixtures/zlic (README table): the tag sets of verify.php
 * as zlic.php reshapes them. Bindings are IP only (docs/licensing.md §3.3):
 * domain (reverse DNS of the caller) and directory are recorded on first bind
 * for information and never validated.
 *
 * Deliberate difference from WHMCS: a wrong PSK is rejected before anything is
 * bound, whereas verify.php (which never sees the PSK) would bind first.
 */
final class Zlic
{
    const MSG_MISSING = 'Missing license or license_PSK';

    const MSG_MD5 = 'MD5 Checksum Verification Failed';

    const MSG_IP = 'IP Address Invalid';

    /** @var LicenseRepository */
    private $repository;

    /** @var callable(string): string */
    private $reverseDns;

    /** @var callable(): int */
    private $clock;

    /** @var callable(string): string */
    private $checkToken;

    /** @var string */
    private $directory;

    /**
     * @param  callable|null  $reverseDns  ip => hostname (default gethostbyaddr)
     * @param  callable|null  $clock  () => unix time
     * @param  callable|null  $checkToken  licensekey => token (default as zlic.php)
     * @param  string  $directory  the "dir" zlic.php has always reported
     */
    public function __construct(
        LicenseRepository $repository,
        $reverseDns = null,
        $clock = null,
        $checkToken = null,
        $directory = '/home/clnixpal/public_html'
    ) {
        $this->repository = $repository;
        $this->reverseDns = $reverseDns ?: function ($ip) {
            return $ip === '' ? '' : (string) gethostbyaddr($ip);
        };
        $this->clock = $clock ?: 'time';
        $this->checkToken = $checkToken ?: function ($key) {
            return time() . md5(mt_rand(100000000, mt_getrandmax()) . $key);
        };
        $this->directory = $directory;
    }

    /**
     * @param  array  $params  merged request input; POST wins over GET (caller's job)
     * @param  string  $clientIp  REMOTE_ADDR
     * @return ZlicResponse
     */
    public function handle(array $params, $clientIp)
    {
        $key = isset($params['license']) ? (string) $params['license'] : '';
        $psk = isset($params['license_PSK']) ? (string) $params['license_PSK'] : '';
        if ($key === '' || $psk === '') {
            return new ZlicResponse(400, ['status' => 'Invalid', 'description' => self::MSG_MISSING]);
        }

        $license = $this->repository->findByKey($key);
        if ($license === null) {
            // No reverse DNS for unknown keys: anyone can send one, and a blocking
            // lookup per request would let them tie up workers.
            $this->repository->log(null, '', $clientIp, $this->directory, 'Invalid Key - ' . substr($key, 0, 64));

            return $this->remote(['status' => 'Invalid']);
        }

        $now = call_user_func($this->clock);
        $license->lastAccess = date('Y-m-d H:i:s', $now);

        if ($this->isInactive($license)) {
            return $this->inactive($license, $clientIp, $psk, $now);
        }

        $pskValid = hash_equals((string) $license->secret, $psk);
        $needsBind = $license->status === License::REISSUED || $license->validIps() === [];
        $boundNow = false;

        // A wrong PSK never binds, so only a valid caller can claim an unbound license.
        // If another request bound it (or staff suspended it) between our read and our
        // write, re-read it and answer from what is stored now.
        if ($needsBind && $pskValid) {
            $this->bind($license, $clientIp);
            if ($this->repository->bind($license, $now)) {
                $boundNow = true;
            } else {
                $license = $this->repository->findByKey($key);
                if ($license === null) {
                    return $this->remote(['status' => 'Invalid']);
                }
                $license->lastAccess = date('Y-m-d H:i:s', $now);
                if ($this->isInactive($license)) {
                    return $this->inactive($license, $clientIp, $psk, $now);
                }
                $needsBind = false;
            }
        }

        // Same order as WHMCS: an IP mismatch is reported before the PSK check
        // (verify.php sends no md5hash for it).
        if (!$needsBind && !$license->allowIpConflict && !in_array($clientIp, $license->validIps(), true)) {
            $this->repository->touch($license, $now);
            $this->log($license, $clientIp, self::MSG_IP);

            return $this->remote(['status' => 'Invalid', 'message' => self::MSG_IP]);
        }
        if (!$boundNow) {
            $this->repository->touch($license, $now);
        }
        if (!$pskValid) {
            $this->log($license, $clientIp, self::MSG_MD5);
        }

        return $this->signed($license, License::ACTIVE, $psk, date('Ymd', $now));
    }

    private function isInactive(License $license)
    {
        return $license->status === License::SUSPENDED || $license->status === License::EXPIRED;
    }

    /** Suspended/Expired: signed status, no local key, nothing bound. */
    private function inactive(License $license, $clientIp, $psk, $now)
    {
        $this->repository->touch($license, $now);
        $this->log($license, $clientIp, 'License ' . $license->status);

        return $this->signed($license, $license->status, $psk);
    }

    /**
     * verify.php tags (+ md5hash), then zlic.php's PSK check and local key.
     *
     * @param  string|null  $checkdate  set for Active results
     */
    private function signed(License $license, $status, $psk, $checkdate = null)
    {
        $token = call_user_func($this->checkToken, $license->licenseKey);

        $results = ['status' => $status]
            + $license->serviceTags
            + [
                'validdomain' => $license->validDomain,
                'validip' => $license->validIp,
                'validdirectory' => $license->validDirectory,
            ];
        // WHMCS's zlic.php reads verify.php's answer with /<(.*?)>([^<]+)<\/\1>/: a tag with nothing in it never matches,
        // so it is in neither the answer nor the local key (AUD7-1: a private customer's empty companyname, an empty
        // billingcycle or validdirectory). Left out here the same way, byte for byte.
        $results = array_filter($results, fn ($value) => $value !== null && $value !== '');
        // verify.php puts customfields after the bindings.
        if (array_key_exists('customfields', $results)) {
            $customfields = $results['customfields'];
            unset($results['customfields']);
            $results['customfields'] = $customfields;
        }
        $results['md5hash'] = md5($license->secret . $token);

        if ($results['md5hash'] !== md5($psk . $token)) {
            $results['status'] = 'Invalid';
            $results['description'] = self::MSG_MD5;

            return new ZlicResponse(200, $results);
        }

        if ($checkdate !== null) {
            $results = LocalKey::attach($results, $license->secret, $checkdate);
        }

        return $this->remote($results);
    }

    private function remote(array $results)
    {
        $results['remotecheck'] = true;

        return new ZlicResponse(200, $results);
    }

    private function bind(License $license, $ip)
    {
        $domain = call_user_func($this->reverseDns, $ip);
        $license->status = License::ACTIVE;
        $license->validIp = $ip;
        $license->validDomain = ($domain === '' || strpos($domain, 'www.') === 0)
            ? $domain
            : $domain . ',www.' . $domain;
        $license->validDirectory = $this->directory;
    }

    private function log($license, $ip, $message)
    {
        $domain = call_user_func($this->reverseDns, $ip);
        $this->repository->log($license, $domain, $ip, $this->directory, $message);
    }
}
