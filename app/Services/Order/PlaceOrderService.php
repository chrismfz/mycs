<?php

namespace App\Services\Order;

use App\Enums\DomainStatus;
use App\Events\Service\Ordered;
use App\Exceptions\CouponUnusableException;
use App\Exceptions\DisplayException;
use App\Jobs\Domains\FulfilDomainOrderJob;
use App\Models\Client;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Services\Domains\Ordering\DomainOffer;
use App\Services\Domains\Ordering\DomainOrderLine;
use App\Services\Service\RenewServiceService;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use MyCS\Pricing\LinePrice;

/**
 * Places an order (Σ7, docs/orders.md): the client's checkout and the staff's «Create Order» go through here, so both
 * price the same way (`OrderPricing`), take stock and coupon uses under a lock, and make the first invoice only when
 * something is due. Services are created here only (`createService`) and start pending; a free one is started at once
 * through `RenewServiceService`, as a paid one is when its invoice is paid: provisioning (Σ1) has one way in.
 */
final class PlaceOrderService
{
    /**
     * @param  list<OrderLine>  $lines
     * @param  string  $invoiceStatus  unpaid, or draft for staff when invoices are immutable once issued
     * @param  string|null  $notes  the order's notes (the customer's at checkout, or staff's): WHMCS «Order Notes»
     * @param  list<DomainOrderLine>  $domains  domains to register or transfer (D8b)
     *
     * @throws DisplayException when a product cannot be ordered (no price in the currency, out of stock, over its
     *                          per-account limit) or the coupon cannot be used ({@see CouponUnusableException})
     */
    public function place(Client $client, string $currency, array $lines, ?Coupon $coupon = null, ?CarbonInterface $dueAt = null, string $invoiceStatus = Invoice::STATUS_UNPAID, ?string $notes = null, array $domains = [], ?string $ip = null): PlacedOrder
    {
        if ($lines === [] && $domains === []) {
            throw new DisplayException(__('Your cart is empty'));
        }
        foreach ($lines as $line) {
            if ($line->quantity < 1) {
                throw new DisplayException(__('The quantity must be at least 1.'));
            }
            // The service trusts no caller for these (ADV-6/7 of the adversarial review).
            if ($line->plan->priceable_type !== Product::class || (int) $line->plan->priceable_id !== $line->product->id) {
                throw new DisplayException(__('product.not_available', ['product' => $line->product->name]));
            }
            if ($line->price !== null && BigDecimal::of($line->price)->isNegative()) {
                throw new DisplayException(__('A price cannot be negative.'));
            }
        }

        $notes = $notes === null ? null : (trim($notes) === '' ? null : trim($notes));

        return DB::transaction(function () use ($client, $currency, $lines, $coupon, $dueAt, $invoiceStatus, $notes, $domains, $ip) {
            // The account's row first, as every payment path locks it (no lock-order deadlock).
            Client::lock($client->id);
            $couponGiven = $coupon !== null;
            $coupon = $couponGiven ? Coupon::query()->whereKey($coupon->id)->lockForUpdate()->first() : null;
            if ($couponGiven && $coupon === null) {
                throw new CouponUnusableException(__('This coupon can no longer be used'));
            }

            $priced = [];
            foreach ($lines as $line) {
                $catalog = OrderPricing::catalog($line->product, $line->plan, $line->configOptions, $currency);
                // A line without a price row in the currency would otherwise check out free.
                if ($catalog === null) {
                    throw new DisplayException(__('product.not_available', ['product' => $line->product->name]));
                }
                $discount = OrderPricing::discount($coupon, $line->product->id, $line->plan);
                $withCoupon = OrderPricing::line($catalog, $discount, $client, $line->product);
                $priced[] = [$line, $withCoupon, $discount !== null && $withCoupon->hasDiscount() ? OrderPricing::line($catalog, null, $client, $line->product) : null];
            }

            $this->takeStock($client, $lines);
            if ($coupon !== null) {
                // A use is a unit: a line of five discounted units is five uses (ADV-1/2).
                $this->checkCoupon($client, $coupon, array_sum(array_map(fn ($p) => $p[2] !== null ? $p[0]->quantity : 0, $priced)),
                    array_map(fn (OrderLine $line) => (int) $line->product->id, $lines));
            }

            // Its IP: the customer's checkout only (WHMCS `ipaddress`), never from a form: not fillable.
            $order = Order::query()->forceCreate(['client_id' => $client->id, 'currency_code' => $currency, 'notes' => $notes,
                'ip_address' => $ip === null ? null : mb_substr($ip, 0, 45)]);
            $invoice = null;

            foreach ($priced as [$line, $price, $fullPrice]) {
                $service = $this->createService($order, $client, $currency, $line, $price, $fullPrice, $coupon);
                // The hook for provisioning on order and for the product's custom fields (Σ1).
                Ordered::dispatch($service, $line);
                $firstPayment = OrderPricing::firstPaymentNet($price, $line->product, $line->plan, now(), $line->price);
                // A pro rata product's first period is set now, to its day (decision 3, PR D); any other starts when paid.
                $period = OrderPricing::firstPeriod($line->product, $line->plan, now());

                if (BigDecimal::of($firstPayment)->isPositive()) {
                    // Made only when something is due (CART-7), with the lines priced in this transaction.
                    $invoice ??= Invoice::query()->create([
                        'client_id' => $client->id,
                        'due_at' => $dueAt ?? now()->addDays(7),
                        'currency_code' => $currency,
                        'status' => $invoiceStatus,
                    ]);
                    $invoice->items()->create([
                        'reference_id' => $service->id,
                        'reference_type' => Service::class,
                        'price' => $firstPayment,
                        'quantity' => $line->quantity,
                    ] + ($period === null ? ['description' => $service->description] : [
                        'description' => $service->describeLine($period['start'], $period['end']),
                        'period_start' => $period['start'],
                        'period_end' => $period['end'],
                    ]));

                    continue;
                }

                // Nothing to pay: started now, the way a payment starts it (one activation path for Σ1: the period
                // from the billing cursor, the server job after commit).
                (new RenewServiceService)->handle($service);
                if ($period !== null) {
                    // A pro rata product's free first period still ends on its day (code review of PR D).
                    $service->forceFill(['expires_at' => $period['end']])->save();
                }
            }

            foreach ($domains as $line) {
                $invoice = $this->placeDomain($order, $client, $currency, $line, $invoice, $dueAt, $invoiceStatus);
            }

            if ($invoice !== null) {
                $order->forceFill(['invoice_id' => $invoice->id])->save(); // its own invoice (Σ1-b2: Cancel & Refund)
            }

            return new PlacedOrder($order, $invoice);
        });
    }

    /**
     * A domain of the order (D8b): checked again as when it was added (the TLD sold, its price for the years, the name
     * not ours already: a register free, a transfer taken), then pending (pending_register / pending_transfer) with its
     * registrant (the other roles: the account's primary contact, D-20), its years, its TLD's registrar and, for a transfer, the EPP
     * code the customer gave. Its line (kind register / transfer, never a renewal) bills it at the catalog price, net of
     * VAT (D-19); one at no price (a free transfer, D-6) has none and waits for its registrar like the others (D8b-2).
     */
    private function placeDomain(Order $order, Client $client, string $currency, DomainOrderLine $line, ?Invoice $invoice, ?CarbonInterface $dueAt, string $invoiceStatus): ?Invoice
    {
        if ((int) $line->registrant->client_id !== (int) $client->id || !$line->registrant->isCompleteFor('domain')) {
            throw new DisplayException(__('domains.order.contact_incomplete'));
        }
        // Under the account's lock: only what the database says (the TLD, its price, the name not ours). Whether the name
        // is free or taken was asked of its registrar before the lock (code review M2): never a registrar call while
        // every payment of the account waits.
        [$fqdn, $tld] = app(DomainOffer::class)->check($line->fqdn, $line->operation, $line->years, $currency, availability: false);
        if ($line->operation === DomainOrderLine::TRANSFER && $tld->epp_code_required && trim((string) $line->eppCode) === '') {
            throw new DisplayException(__('domains.order.epp_required', ['domain' => $fqdn]));
        }
        $price = (string) $tld->priceFor($line->operation, $line->years, $currency);
        // Only a transfer may be free (D-6): a registration at 0.00 is a price mistake, never registered at our cost
        // (code review 9).
        $free = !BigDecimal::of($price)->isPositive();
        if ($free && $line->operation === DomainOrderLine::REGISTER) {
            throw new DisplayException(__('domains.order.not_offered'));
        }
        [$sld] = explode('.', $fqdn, 2);
        try {
            $domain = Domain::query()->create([
                'client_id' => $client->id, 'order_id' => $order->id, 'domain_tld_id' => $tld->id,
                'registrar_connection_id' => $tld->registrar_connection_id,
                'sld' => $sld, 'tld' => $tld->tld, 'fqdn' => $fqdn,
                'status' => $line->operation === DomainOrderLine::REGISTER ? DomainStatus::PendingRegister : DomainStatus::PendingTransfer,
                'years' => $line->years, 'currency_code' => $currency,
                'auto_renew' => (bool) config('settings.domain_default_auto_renew', true), // Settings → Domains
                // The owner as chosen; the account's own details for the other roles (D-20, code review L6).
                'registrant_contact_id' => $line->registrant->id, 'admin_contact_id' => $client->ensurePrimaryContact()->id,
                'tech_contact_id' => $client->ensurePrimaryContact()->id, 'billing_contact_id' => $client->ensurePrimaryContact()->id,
                'epp_code' => $line->operation === DomainOrderLine::TRANSFER ? trim((string) $line->eppCode) : null,
                // Free as ordered, recorded (code review 1): a line removed later never makes it free.
                'meta' => $free ? ['free_order' => true] : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DisplayException(__('domains.order.already_here', ['domain' => $fqdn])); // ordered a moment ago
        }
        $net = OrderPricing::toNet($price, $client);
        if (!BigDecimal::of($net)->isPositive()) {
            // Nothing to pay (a free transfer, D-6): sent to its registrar once this order commits (D8b-2).
            $domainId = (int) $domain->id;
            DB::afterCommit(fn () => FulfilDomainOrderJob::dispatch($domainId));

            return $invoice;
        }
        $invoice ??= Invoice::query()->create([
            'client_id' => $client->id,
            'due_at' => $dueAt ?? now()->addDays(7),
            'currency_code' => $currency,
            'status' => $invoiceStatus,
        ]);
        $invoice->items()->create([
            'reference_id' => $domain->id,
            'reference_type' => Domain::class,
            'kind' => $line->operation === DomainOrderLine::REGISTER ? InvoiceItem::KIND_REGISTER : InvoiceItem::KIND_TRANSFER,
            'price' => $net,
            'quantity' => 1,
            'description' => trans_choice('domains.order.line_' . $line->operation, $line->years, ['domain' => $fqdn, 'years' => $line->years]),
        ]);

        return $invoice;
    }

    /** @param  list<OrderLine>  $lines */
    private function takeStock(Client $client, array $lines): void
    {
        $quantities = [];
        foreach ($lines as $line) {
            $quantities[$line->product->id] = ($quantities[$line->product->id] ?? 0) + $line->quantity;
        }
        ksort($quantities);

        foreach ($quantities as $productId => $quantity) {
            // The latest product row, locked: two orders cannot both take the last unit.
            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            // Retired (WHMCS «Retired»): not ordered any more, whatever its stock says (ADV-1 of #216).
            if ($product->retired) {
                throw new DisplayException(__('product.retired', ['product' => $product->name]));
            }

            if ($product->per_user_limit > 0) {
                // In units, as the new lines (ADV-3). Cancelled services do not count (CART-18): an order that was
                // never paid does not use the limit up.
                // A locking read: a plain one could see a snapshot taken before the account's lock and miss a
                // concurrent order (MariaDB REPEATABLE READ).
                $existing = (int) $client->services()->where('product_id', $product->id)
                    ->whereNotIn('status', [Service::STATUS_CANCELLED])->lockForUpdate()->sum('quantity');
                if ($existing + $quantity > $product->per_user_limit) {
                    throw new DisplayException(__('product.user_limit', ['product' => $product->name]));
                }
            }
            if ($product->stock !== null) {
                if ($product->stock < $quantity) {
                    throw new DisplayException(__('product.out_of_stock', ['product' => $product->name]));
                }
                $product->stock -= $quantity;
                $product->save();
            }
        }
    }

    /**
     * Under the coupon's lock, counted with locking reads so a concurrent order's uses are seen (CART-5): every
     * discounted unit is a use, not the checkout once.
     */
    /** @param  list<int>  $productIds */
    private function checkCoupon(Client $client, Coupon $coupon, int $uses, array $productIds): void
    {
        if (($coupon->starts_at && $coupon->starts_at->isFuture())
            || ($coupon->expires_at && $coupon->expires_at->isPast())) {
            throw new CouponUnusableException(__('This coupon can no longer be used'));
        }
        // Who may use it (new or existing customers, once each, with a product it requires): under the coupon's lock.
        if (($refusal = $coupon->refusalFor($client, $productIds)) !== null) {
            throw new CouponUnusableException($refusal);
        }
        if ($uses === 0) {
            return;
        }
        if ($coupon->max_uses && $this->unitsUsed($coupon->services()) + $uses > $coupon->max_uses) {
            throw new CouponUnusableException(__('This coupon can no longer be used'));
        }
        if ($coupon->max_uses_per_user
            && $this->unitsUsed($coupon->services()->where('client_id', $client->id)) + $uses > $coupon->max_uses_per_user) {
            throw new CouponUnusableException(__('This coupon can no longer be used'));
        }
    }

    /** @param  HasMany<Service, Coupon>  $services */
    private function unitsUsed(HasMany $services): int
    {
        return (int) $services->lockForUpdate()->sum('quantity');
    }

    private function createService(Order $order, Client $client, string $currency, OrderLine $line, LinePrice $price, ?LinePrice $fullPrice, ?Coupon $coupon): Service
    {
        // The price the service renews at: staff's, or the catalog's. A coupon for the first cycle only discounts
        // the first invoice; one for more cycles (or all) is kept in the price until the cron ends it.
        $renewal = $line->price ?? ($fullPrice !== null && $coupon?->cycles() === 1 ? $fullPrice->netPrice : $price->netPrice);

        $service = $order->services()->create([
            'client_id' => $client->id,
            'currency_code' => $currency,
            'product_id' => $line->product->id,
            'plan_id' => $line->plan->id,
            'price' => $renewal,
            'quantity' => $line->quantity,
            // Only where it discounted something (CART-2): otherwise it counts as a use and a recalculation applies it.
            'coupon_id' => $fullPrice !== null ? $coupon?->id : null,
        ]);

        foreach ($line->checkoutConfig as $key => $value) {
            $service->properties()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $options = $line->product->configOptions->keyBy('id');
        foreach ($line->configOptions as $chosen) {
            $chosen = (object) $chosen;
            /** @var ConfigOption|null $option */
            $option = $options->get($chosen->option_id ?? null);
            // Only the product's current options (CART-10): a stale cart must not give the service a config it did
            // not pay for.
            if ($option === null || !isset($chosen->value) || $chosen->value === '') {
                continue;
            }
            if (in_array($option->type, ['text', 'number'], true)) {
                $service->properties()->updateOrCreate(
                    ['key' => $option->env_variable ?: $option->name],
                    ['name' => $option->name, 'value' => $chosen->value],
                );

                continue;
            }
            $valueId = $option->type === 'checkbox'
                ? (OrderPricing::ticked($chosen->value) ? $option->children->first()?->id : null)
                : $option->children->firstWhere('id', $chosen->value)?->id;
            if ($valueId === null) {
                continue;
            }
            $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $valueId]);
        }

        return $service;
    }
}
