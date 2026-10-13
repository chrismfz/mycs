<?php

namespace App\Http\Requests\Api\Admin\InvoiceItems;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Closure;

/**
 * What an invoice line made through the API may point at (audit sweep AUTH-8 / DATA-19): a service (renewal) or a
 * service upgrade of the invoice's own customer, or nothing. Any class name used to be accepted and instantiated
 * (app($class)); a line pointing at Credit sold account credit, and one pointing at another customer's service
 * renewed it when this invoice was paid.
 */
final class InvoiceItemReference
{
    public const TYPES = [Service::class, ServiceUpgrade::class];

    /**
     * The rule for reference_id, given the invoice the line is (or will be) on.
     *
     * @param  Closure(): ?Invoice  $invoice
     */
    public static function rule(Closure $invoice, ?string $type): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($invoice, $type) {
            if ($type === null || $type === '') {
                return;
            }
            if (!$value) {
                $fail('The reference_id field is required when reference_type is provided.');

                return;
            }
            $owner = match ($type) {
                Service::class => Service::query()->whereKey($value)->value('user_id'),
                ServiceUpgrade::class => ServiceUpgrade::query()->whereKey($value)->first()?->service?->user_id,
                default => null,
            };
            if ($owner === null) {
                $fail('The selected reference_id is invalid for the given reference_type.');

                return;
            }
            $target = $invoice();
            if ($target !== null && (int) $owner !== (int) $target->user_id) {
                $fail('The referenced service belongs to another customer than the invoice.');
            }
        };
    }
}
