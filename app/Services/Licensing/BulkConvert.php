<?php

namespace App\Services\Licensing;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\License;
use App\Models\LicenseIp;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Services\Order\OrderPricing;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * The client's licenses that may move into $bulk: active and renewing (a due date, no cancellation asked, nothing
     * open to pay, neither an addon nor with one), standalone (not bulk, no list, Active: bound or never, not waiting for a reissue, no «Allow IP
     * conflict»), not moving already, and checked with the same secret (PSK) as the bulk product: their agents keep sending
     * it. Anything else is left to staff by hand (review of #850: a cut, not a case): its join date and IPs are then known.
     *
     * @return Collection<int, Service>
     */
    public static function candidates(Service $bulk): Collection
    {
        $secret = self::secret($bulk->product);

        return Service::query()->where('client_id', $bulk->client_id)->whereKeyNot($bulk->id)
            ->where('status', Service::STATUS_ACTIVE)->whereNotNull('expires_at')->where('expires_at', '>', now())
            ->whereDoesntHave('cancellation')
            ->whereNull('parent_service_id')
            ->whereDoesntHave('addons', fn ($addons) => $addons->whereIn('status', Service::PAYING_STATUSES))
            ->whereDoesntHave('invoices', fn ($invoices) => $invoices->whereIn('invoices.status', Invoice::OPEN_STATUSES))
            ->whereHas('license', fn ($license) => $license->whereNull('bulk_license_id')->where('status', License::STATUS_ACTIVE)
                ->whereDoesntHave('ips', fn ($ips) => $ips->whereNull('removed_at')))
            ->with(['license', 'product.settings', 'plan'])->orderBy('expires_at')->orderBy('id')->get()
            ->filter(fn (Service $service) => !Licenses::isBulk($service) && $secret !== '' && self::secret($service->product) === $secret
                && !filter_var(ExtensionHelper::settingsToArray($service->product->settings ?? collect())['allow_ip_conflict'] ?? false, FILTER_VALIDATE_BOOLEAN))
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
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw new DisplayException(__('No license selected.'));
        }

        try {
            return DB::transaction(fn () => self::move($bulk, $ids));
        } catch (UniqueConstraintViolationException) {
            // The customer asked to cancel one meanwhile (one request a service): nothing moved.
            throw new DisplayException(__('A cancellation was asked for one of them meanwhile: nothing moved.'));
        }
    }

    /** @param  list<int>  $ids */
    private static function move(Service $bulk, array $ids): int
    {
        // Under the services' locks, in id order, read again: the bulk ending, a cancellation, a payment or another move
        // meanwhile is seen.
        $locked = Service::query()->whereKey([...$ids, $bulk->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $bulk = $locked->get($bulk->id) ?? $bulk;
        // Running and not cancelling (ADV-850-4): it outlives the licenses it takes in.
        if (!Licenses::isBulk($bulk) || $bulk->license === null || !in_array($bulk->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)
            || $bulk->cancellation()->exists()) {
            throw new DisplayException(__('Only a running bulk license takes standalone ones in.'));
        }
        $eligible = self::candidates($bulk)->keyBy('id');
        foreach ($ids as $id) {
            if (!$eligible->has($id)) {
                throw new DisplayException(__('Service #:id cannot move into this bulk license (not an active, renewing standalone license of this client with the same secret, nothing open to pay and no addons).', ['id' => $id]));
            }
        }
        foreach ($ids as $id) {
            $service = $eligible->get($id);
            // The license first: the cancellation's mails ask it (moving()).
            $service->license->update(['bulk_license_id' => $bulk->license->id]);
            ServiceCancellation::query()->create(['service_id' => $id, 'type' => 'end_of_period',
                'reason' => __('Moved into bulk license (service #:id)', ['id' => $bulk->id])]);
        }

        return count($ids);
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
        // Still running, and still this client's (ADV-850-3): else it ends as any license.
        if ($bulk === null || !in_array($bulk->service?->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)
            || (int) $bulk->service->client_id !== (int) $service->client_id) {
            return;
        }
        $ips = self::ipsOf($license);
        if ($ips !== []) {
            // Already listed ones are kept as they are (a retry, or the customer added it before).
            BulkLicenseIps::add($bulk, implode("\n", array_map(fn (string $ip) => $ip . ' ' . __('service #:id', ['id' => $service->id]), $ips)));
        }
        License::query()->whereKey($license->id)->whereNull('merged_at')->update(['merged_at' => now()]);
    }

    /**
     * A move withdrawn before it joined (its cancellation deleted while the service still runs): the license is standalone
     * again. Not once the service ended (review of #850): a failed join waiting for its retry stays a move.
     */
    public static function withdraw(int $serviceId): void
    {
        License::query()->where('service_id', $serviceId)->whereNull('merged_at')
            ->whereHas('service', fn ($service) => $service->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED]))
            ->update(['bulk_license_id' => null]);
    }

    /** Moving into a bulk license: its cancellation request is staff's, no mail or chat card for it. */
    public static function moving(?Service $service): bool
    {
        return $service !== null && License::query()->where('service_id', $service->id)->whereNotNull('bulk_license_id')->exists();
    }

    /**
     * Joined a bulk license: its end sends no mail. One that ended without joining (the bulk ended first, or it was ended
     * for another reason) is told as any (review of #850).
     */
    public static function joined(?Service $service): bool
    {
        return $service !== null && License::query()->where('service_id', $service->id)->whereNotNull('merged_at')->exists();
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
