<?php

namespace App\Services\Licensing;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\License;
use App\Models\LicenseIp;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Services\Order\OrderPricing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A client's standalone licenses moved into their bulk one (docs/zonecloud-bulk-license.md §8.1). Staff pick them; each
 * gets a cancellation at the end of its period and keeps working as it is until then. When it ends, its IPs join the bulk
 * list that day and its key answers as the bulk license (MycsLicenseRepository::answering). No day is paid twice, no
 * credit, nothing for the customer to change on their servers, and no mail (owner 2026-11-13).
 */
class BulkConvert
{
    /**
     * The client's licenses that may move into $bulk: running, renewing (a due date, no cancellation asked), standalone
     * (not bulk, no list), not moving already, and checked with the same secret (PSK) as the bulk product: their agents
     * keep sending it.
     *
     * @return Collection<int, Service>
     */
    public static function candidates(Service $bulk): Collection
    {
        $secret = self::secret($bulk->product);

        return Service::query()->where('client_id', $bulk->client_id)->whereKeyNot($bulk->id)
            ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])->whereNotNull('expires_at')
            ->whereDoesntHave('cancellation')
            ->whereHas('license', fn ($license) => $license->whereNull('bulk_license_id')->where('status', '!=', License::STATUS_EXPIRED)
                ->whereDoesntHave('ips', fn ($ips) => $ips->whereNull('removed_at')))
            ->with(['license', 'product.settings', 'plan'])->orderBy('expires_at')->orderBy('id')->get()
            ->filter(fn (Service $service) => !Licenses::isBulk($service) && $secret !== '' && self::secret($service->product) === $secret)
            ->values();
    }

    /**
     * What moving $ids would do: how many, their IPs (new to the list), the days they join, and the bulk's monthly cost
     * once all are in.
     *
     * @param  list<int>  $ids
     * @return array{licenses: int, ips: int, without: int, from: ?string, to: ?string, monthly: ?string}
     */
    public static function preview(Service $bulk, array $ids): array
    {
        $moving = self::candidates($bulk)->whereIn('id', $ids);
        $listed = $bulk->license?->ips()->active()->pluck('ip')->all() ?? [];
        $new = $moving->flatMap(fn (Service $service) => self::ipsOf($service->license))->unique()->diff($listed);
        try {
            $monthly = OrderPricing::toNet((string) BulkUsage::tiers($bulk)->cost(count($listed) + $new->count()), $bulk->client, $bulk->product);
        } catch (DisplayException) {
            $monthly = null;
        }

        return [
            'licenses' => $moving->count(),
            'ips' => $new->count(),
            'without' => $moving->filter(fn (Service $service) => self::ipsOf($service->license) === [])->count(),
            'from' => $moving->min('expires_at')?->format('d/m/Y'),
            'to' => $moving->max('expires_at')?->format('d/m/Y'),
            'monthly' => $monthly,
        ];
    }

    /**
     * Moves $ids into $bulk: each license is marked for it and its service gets a cancellation at the end of its period.
     * All or none: one that may not move refuses the lot.
     *
     * @param  list<int>  $ids
     * @return int how many
     */
    public static function convert(Service $bulk, array $ids): int
    {
        if (!Licenses::isBulk($bulk) || $bulk->license === null || !in_array($bulk->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
            throw new DisplayException(__('Only a running bulk license takes standalone ones in.'));
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw new DisplayException(__('No license selected.'));
        }

        return DB::transaction(function () use ($bulk, $ids) {
            // Under their services' locks, read again: a cancellation, a payment or another move meanwhile is seen.
            Service::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            $eligible = self::candidates($bulk)->keyBy('id');
            foreach ($ids as $id) {
                if (!$eligible->has($id)) {
                    throw new DisplayException(__('Service #:id cannot move into this bulk license (not a running standalone license of this client with the same secret, or already cancelling).', ['id' => $id]));
                }
            }
            foreach ($ids as $id) {
                $service = $eligible->get($id);
                // The license first: the cancellation's mails ask it (silent()).
                $service->license->update(['bulk_license_id' => $bulk->license->id]);
                ServiceCancellation::query()->create(['service_id' => $id, 'type' => 'end_of_period',
                    'reason' => __('Moved into bulk license (service #:id)', ['id' => $bulk->id])]);
            }

            return count($ids);
        });
    }

    /**
     * At its end (its module's terminate): a license moving into a bulk one joins it now. Its IPs go on the bulk list
     * from today, named after its service, and its key answers as the bulk license since. Only an end its cancellation
     * asked for (a service ended for another reason just ends), and only into a bulk still running: else it ends as any.
     * A refusal (its IP on someone else's bulk list) fails the module action for staff; its retry joins it.
     */
    public static function join(Service $service): void
    {
        $license = License::query()->where('service_id', $service->id)->first();
        if ($license === null || $license->bulk_license_id === null || $license->merged_at !== null
            || $service->fresh()?->status !== Service::STATUS_CANCELLED) {
            return;
        }
        $bulk = License::query()->with('service.product')->find($license->bulk_license_id);
        if ($bulk === null || !in_array($bulk->service?->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
            return;
        }
        $ips = self::ipsOf($license);
        if ($ips !== []) {
            // Already listed ones are kept as they are (a retry, or the customer added it before).
            BulkLicenseIps::add($bulk, implode("\n", array_map(fn (string $ip) => $ip . ' ' . __('service #:id', ['id' => $service->id]), $ips)));
        }
        License::query()->whereKey($license->id)->whereNull('merged_at')->update(['merged_at' => now()]);
    }

    /** A move withdrawn before it joined (its cancellation deleted): the license is standalone again. */
    public static function withdraw(int $serviceId): void
    {
        License::query()->where('service_id', $serviceId)->whereNull('merged_at')->update(['bulk_license_id' => null]);
    }

    /** Moving into, or joined, a bulk license: its cancellation and its end send the customer no mail. */
    public static function silent(?Service $service): bool
    {
        return $service !== null && License::query()->where('service_id', $service->id)->whereNotNull('bulk_license_id')->exists();
    }

    /** @return list<string> a standalone license's bound IPs, as the bulk list keeps them */
    public static function ipsOf(?License $license): array
    {
        $ips = array_map(fn (string $ip) => LicenseIp::normalize($ip), preg_split('/[\s,]+/', (string) $license?->valid_ip) ?: []);

        return array_values(array_unique(array_filter($ips)));
    }

    private static function secret(?Product $product): string
    {
        return (string) (ExtensionHelper::settingsToArray($product->settings ?? collect())['secret_key'] ?? '');
    }
}
