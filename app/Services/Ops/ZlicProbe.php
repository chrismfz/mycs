<?php

namespace App\Services\Ops;

use App\Http\Controllers\ZlicController;
use App\Services\Licensing\MycsLicenseRepository;
use Nixpal\Licensing\License;
use Nixpal\Licensing\LicenseRepository;
use Nixpal\Licensing\Zlic;

/**
 * What zlic.php would answer a license's own client from an address (docs/ops.md), without writing anything: no
 * binding, no last access, no log row, no reverse DNS. It sends the product's own PSK, which never leaves (nor the
 * md5hash and localkey made with it).
 */
final class ZlicProbe
{
    /** @return array<string, mixed> */
    public static function answer(string $key, string $ip): array
    {
        $repository = new class(new MycsLicenseRepository) implements LicenseRepository
        {
            public bool $wouldBind = false;

            /** @var list<string> */
            public array $wouldLog = [];

            public function __construct(private MycsLicenseRepository $inner) {}

            public function findByKey($licenseKey)
            {
                return $this->inner->findByKey($licenseKey);
            }

            public function touch(License $license, $now) {}

            public function bind(License $license, $now)
            {
                $this->wouldBind = true;

                return true;
            }

            public function log($license, $domain, $ip, $directory, $message)
            {
                $this->wouldLog[] = (string) $message;
            }
        };
        $license = $repository->findByKey($key);
        if ($license === null) {
            return ['status' => 'Invalid', 'detail' => 'No such license key'];
        }

        $response = (new Zlic($repository, fn () => '', null, null, ZlicController::DIRECTORY))
            ->handle(['license' => $key, 'license_PSK' => (string) $license->secret], $ip);
        $body = $response->body;

        return [
            'status' => $body['status'] ?? null,
            'message' => $body['message'] ?? $body['description'] ?? null,
            'http' => $response->httpStatus,
            'would_bind' => $repository->wouldBind ? $ip : null,
            'would_log' => $repository->wouldLog,
            'license_status' => $license->status,
            'bound_ips' => $license->validIps(),
            'psk_set' => (string) $license->secret !== '',
        ];
    }
}
