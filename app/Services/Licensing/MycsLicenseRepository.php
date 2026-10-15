<?php

namespace App\Services\Licensing;

use App\Helpers\ExtensionHelper;
use App\Models\License as LicenseRow;
use App\Models\LicenseAccessLog;
use App\Models\Plan;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Nixpal\Licensing\License;
use Nixpal\Licensing\LicenseRepository;

/**
 * The licenses zlic.php reads, from the MyCS database (packages/nixpal-licensing; WhmcsLicenseRepository reads WHMCS's).
 * The answer's tags are verify.php's, from the service, its client and its product's settings (the NixpalLicensing
 * server extension): the PSK, «allow IP conflict», and the custom fields reported (WHMCS's product custom fields,
 * kept as the service's properties).
 */
final class MycsLicenseRepository implements LicenseRepository
{
    public const UNKNOWN_PER_MINUTE = 10;

    /** Messages logged once a day per license and address. */
    public const REPEATED = ['License Expired', 'License Suspended'];

    public function findByKey($licenseKey)
    {
        $row = LicenseRow::query()->where('license_key', (string) $licenseKey)
            ->with(['service.product.settings', 'service.plan', 'service.client.primaryContact', 'service.properties'])
            ->first();
        if ($row === null || $row->service === null) {
            return null;
        }
        $service = $row->service;
        $settings = ExtensionHelper::settingsToArray($service->product->settings ?? collect());
        $contact = $service->client?->primaryContact;

        $license = new License;
        $license->id = $row->id;
        $license->licenseKey = $row->license_key;
        // As verify.php, the service's state wins (review ADV-L1): an ended service answers Expired, a suspended or not yet
        // active one Suspended, also when its server action never ran (a failed job, a product moved off the server).
        $license->status = self::effectiveStatus($row->status, $service->status);
        $license->validDomain = (string) $row->valid_domain;
        $license->validIp = (string) $row->valid_ip;
        $license->validDirectory = (string) $row->valid_directory;
        $license->reissues = $row->reissues;
        $license->lastAccess = $row->last_access?->format('Y-m-d H:i:s');
        $license->secret = (string) ($settings['secret_key'] ?? '');
        $license->allowIpConflict = filter_var($settings['allow_ip_conflict'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $tags = [
            'registeredname' => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
            'companyname' => (string) ($contact->company_name ?? ''),
            'email' => (string) ($contact->email ?? ''), // the account's own, as tblclients.email (review R9)
            // The importer keeps WHMCS's ids: the id clients have in their stored localkey.
            'serviceid' => (string) $service->id,
            'productid' => (string) $service->product_id,
            'productname' => (string) ($service->product->name ?? ''),
            // verify.php prints the registration date as a datetime at midnight.
            // In the company's time zone, as WHMCS's local regdate (review R10).
            'regdate' => $service->created_at?->copy()->setTimezone((string) config('settings.timezone', 'UTC'))->format('Y-m-d') . ' 00:00:00',
            'nextduedate' => $service->expires_at?->format('Y-m-d') ?? '0000-00-00',
            // WHMCS's own cycle when imported (review R8: a free or retired plan is imported without one).
            'billingcycle' => (string) ($service->properties->firstWhere('key', 'whmcs_billingcycle')->value ?? self::billingCycle($service->plan)),
        ];
        $customfields = self::customFields($service, (string) ($settings['custom_fields'] ?? ''));
        if ($customfields !== null) {
            $tags['customfields'] = $customfields;
        }
        $license->serviceTags = $tags;

        return $license;
    }

    public function touch(License $license, $now)
    {
        LicenseRow::query()->whereKey($license->id)->update(['last_access' => Carbon::createFromTimestamp($now)]);
    }

    /** As WhmcsLicenseRepository::bind: only a license still unbound or Reissued, and never over staff's suspend. */
    public function bind(License $license, $now)
    {
        return LicenseRow::query()->whereKey($license->id)
            ->whereIn('status', [LicenseRow::STATUS_ACTIVE, LicenseRow::STATUS_REISSUED])
            ->where(fn ($query) => $query->where('status', LicenseRow::STATUS_REISSUED)
                ->orWhereNull('valid_ip')->orWhereRaw("TRIM(REPLACE(valid_ip, ',', '')) = ''"))
            ->update([
                'valid_domain' => $license->validDomain,
                'valid_ip' => $license->validIp,
                'valid_directory' => $license->validDirectory,
                'status' => $license->status,
                'last_access' => Carbon::createFromTimestamp($now),
            ]) === 1;
    }

    public function log($license, $domain, $ip, $directory, $message)
    {
        // Unknown keys (unauthenticated) are logged at most UNKNOWN_PER_MINUTE times a minute per address (review ADV-L6).
        if ($license === null && Cache::increment('zlic:unknown:' . $ip . ':' . now()->format('YmdHi')) > self::UNKNOWN_PER_MINUTE) {
            return;
        }
        // Dead agents of an ended or suspended license keep asking (WHMCS logged ~47k a week): once a day per license,
        // address and message. The answer is the same; every check is still counted (license_check_days, last_access).
        if ($license !== null && in_array($message, self::REPEATED, true) && !self::firstToday($license->id, $ip, $message)) {
            return;
        }
        LicenseAccessLog::query()->create([
            'license_id' => $license?->id,
            'domain' => mb_substr((string) $domain, 0, 255),
            'ip' => mb_substr((string) $ip, 0, 64),
            'directory' => mb_substr((string) $directory, 0, 255),
            'message' => mb_substr((string) $message, 0, 255),
            'created_at' => now(),
        ]);
    }

    /**
     * The first such row today for this license, address and message (Cache::add). A cache outage answers $onError: the
     * failures are logged rather than lost (review R-6), «License Active» rows skipped rather than one per check.
     */
    public static function firstToday(int $licenseId, string $ip, string $message, bool $onError = true): bool
    {
        try {
            return Cache::add('zlic:log:' . $licenseId . ':' . md5($ip . '|' . $message) . ':' . now()->format('Ymd'), 1, now()->addDay());
        } catch (\Throwable $e) {
            report($e);

            return $onError;
        }
    }

    public static function effectiveStatus(string $license, string $service): string
    {
        return match ($service) {
            Service::STATUS_CANCELLED, Service::STATUS_TERMINATED => LicenseRow::STATUS_EXPIRED,
            Service::STATUS_SUSPENDED, Service::STATUS_PENDING => $license === LicenseRow::STATUS_EXPIRED ? $license : LicenseRow::STATUS_SUSPENDED,
            default => $license,
        };
    }

    /** WHMCS's billing cycle names (verify.php's «billingcycle»). */
    public static function billingCycle(?Plan $plan): string
    {
        if ($plan === null || $plan->type === 'free') {
            return 'Free Account';
        }
        if ($plan->type === 'one-time') {
            return 'One Time';
        }
        $months = match ($plan->billing_unit) {
            'year' => 12 * $plan->billing_period,
            'month' => $plan->billing_period,
            default => null,
        };

        return match ($months) {
            1 => 'Monthly',
            3 => 'Quarterly',
            6 => 'Semi-Annually',
            12 => 'Annually',
            24 => 'Biennially',
            36 => 'Triennially',
            default => ucfirst((string) $plan->billing_unit),
        };
    }

    /**
     * The names in a product's «custom_fields» setting: comma separated as staff type them, or a JSON list where a WHMCS
     * field name has a comma or edge spaces (WhmcsLicensingImport), reported exactly as WHMCS did (review R-2).
     *
     * @return list<string>
     */
    public static function fieldNames(string $setting): array
    {
        $list = str_starts_with(ltrim($setting), '[') ? json_decode($setting, true) : null;
        if (is_array($list)) {
            return array_values(array_filter(array_map('strval', $list), fn ($name) => $name !== ''));
        }

        return array_values(array_filter(array_map('trim', explode(',', $setting)), fn ($name) => $name !== ''));
    }

    /** The setting for these names: comma separated, or JSON when a name would not survive that (fieldNames). */
    public static function fieldSetting(array $names): string
    {
        foreach ($names as $name) {
            if (str_contains($name, ',') || $name !== trim($name) || str_starts_with($name, '[')) {
                return (string) json_encode(array_values($names), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return implode(',', $names);
    }

    /**
     * «name=value|name=value» of the fields the product reports (its «custom_fields» setting, e.g. «comments»), from
     * the service's properties; null when the product reports none (verify.php then leaves the tag out).
     */
    private static function customFields(Service $service, string $fields): ?string
    {
        $names = self::fieldNames($fields);
        if ($names === []) {
            return null;
        }
        $values = $service->properties->pluck('value', 'key');

        return implode('|', array_map(fn ($name) => $name . '=' . urlencode((string) ($values[$name] ?? '')), $names));
    }
}
