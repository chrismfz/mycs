<?php

namespace App\Services\Licensing;

use App\Helpers\ExtensionHelper;
use App\Models\License as LicenseRow;
use App\Models\LicenseAccessLog;
use App\Models\Plan;
use App\Models\Service;
use Illuminate\Support\Carbon;
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
        $license->status = $row->status;
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
            'email' => (string) ($contact->email ?? $service->client->email ?? ''),
            // The importer keeps WHMCS's ids: the id clients have in their stored localkey.
            'serviceid' => (string) $service->id,
            'productid' => (string) $service->product_id,
            'productname' => (string) ($service->product->name ?? ''),
            // verify.php prints the registration date as a datetime at midnight.
            'regdate' => $service->created_at?->format('Y-m-d') . ' 00:00:00',
            'nextduedate' => $service->expires_at?->format('Y-m-d') ?? '0000-00-00',
            'billingcycle' => self::billingCycle($service->plan),
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
        LicenseAccessLog::query()->create([
            'license_id' => $license?->id,
            'domain' => mb_substr((string) $domain, 0, 255),
            'ip' => mb_substr((string) $ip, 0, 64),
            'directory' => mb_substr((string) $directory, 0, 255),
            'message' => mb_substr((string) $message, 0, 255),
            'created_at' => now(),
        ]);
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
     * «name=value|name=value» of the fields the product reports (its «custom_fields» setting, e.g. «comments»), from
     * the service's properties; null when the product reports none (verify.php then leaves the tag out).
     */
    private static function customFields(Service $service, string $fields): ?string
    {
        $names = array_values(array_filter(array_map('trim', explode(',', $fields)), fn ($name) => $name !== ''));
        if ($names === []) {
            return null;
        }
        $values = $service->properties->pluck('value', 'key');

        return implode('|', array_map(fn ($name) => $name . '=' . urlencode((string) ($values[$name] ?? '')), $names));
    }
}
