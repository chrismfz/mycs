<?php

namespace App\Services\Payment;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\Invoice\InvoiceLineMove;
use App\Services\Invoice\SplitInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A service's or domain's own payment method (owner 2026-10-29, as WHMCS's paymentmethod): none is its account's (the
 * saved method charges its renewals, as before); a gateway's, its renewals are invoiced apart with it preferred, charged
 * by the account's saved method of that gateway if it has one (owner 2026-11-01), never by one of another gateway (their
 * lines record it: AutoCharge::agreementFor). Set by the customer on its page, by
 * staff in the admin, from WHMCS at import; its open invoices follow a change (followOnOpenInvoices, owner 2026-11-01).
 * Its own «Do not charge automatically» keeps any method from its invoices (#568).
 */
final class ItemPaymentMethod
{
    /** @return Collection<int, Gateway> the gateways it may pay with: those its invoice would offer */
    public static function options(Service|Domain $item): Collection
    {
        $amount = $item instanceof Service ? (float) $item->price : (float) $item->recurring_amount;

        return collect(ExtensionHelper::getCheckoutGateways($amount, (string) $item->currency_code, 'invoice'));
    }

    /** Its own payment method set, or the account's (null); a gateway not on offer is refused. */
    public static function set(Service|Domain $item, ?int $gatewayId): void
    {
        if ($gatewayId === ($item->payment_gateway_id === null ? null : (int) $item->payment_gateway_id)) {
            return; // as it is (one no longer offered stays until another is chosen)
        }
        if ($gatewayId !== null && !self::options($item)->contains(fn (Gateway $gateway) => (int) $gateway->id === $gatewayId)) {
            throw new DisplayException(__('services.payment_method_not_available'));
        }
        self::$leftWhole = [];
        $item->update(['payment_gateway_id' => $gatewayId]);
    }

    /** @var list<string> the open invoices the last change left as they were (they cannot be split): '#N' */
    private static array $leftWhole = [];

    /**
     * What the customer is told after set() or setAutoCharge(): $saved, and, of an open invoice the change could not
     * leave (one that is Ekdosi's, or with a payment on it: InvoiceLineMove::refusal), that it stays as it is (REV-778-4).
     */
    public static function notice(string $saved, string $leftWholeKey): string
    {
        [$left, self::$leftWhole] = [array_values(array_unique(self::$leftWhole)), []];

        return $left === [] ? $saved : trans_choice($leftWholeKey, count($left), ['invoices' => implode(', ', $left)]);
    }

    /** Its own payment method's gateway, still there (a deleted one: none, the account's). Its lines record it when billed. */
    public static function live(Service|Domain $item): ?int
    {
        $own = $item->payment_gateway_id === null ? null : (int) $item->payment_gateway_id;

        return $own !== null && Gateway::query()->whereKey($own)->exists() ? $own : null;
    }

    /**
     * The payment method its renewals go apart by (WHMCS bills each payment method on its own invoice): its own (live),
     * unless it is its account's saved method's gateway (given, or read), which charges the rest: none then, one invoice
     * with them. Not the gateway it last paid with (round 2 of #541: a card saved later would find a PayPal line among
     * the ones it should charge).
     */
    public static function apart(Service|Domain $item, int|false|null $account = false): ?int
    {
        $own = self::live($item);
        if ($own === null) {
            return null;
        }
        $account = $account === false ? Invoice::savedMethodGatewayFor((int) $item->client_id) : $account;

        return $own === $account ? null : $own;
    }

    /**
     * The saved method that charges its renewals: the account's, or the account's method of its own payment method's
     * gateway when that is another (Client::autoChargeMethodFor, owner 2026-11-01); none of that gateway, none.
     */
    public static function chargedBy(Service|Domain $item): ?BillingAgreement
    {
        if ($item->no_auto_charge) {
            return null; // its own «Do not charge automatically» (#568)
        }
        $own = $item->payment_gateway_id === null || $item->paymentGateway === null ? null : (int) $item->payment_gateway_id; // deleted: the account's

        return $item->client?->autoChargeAgreementFor($own);
    }

    /**
     * A payment method as its select offers it (owner 2026-11-01: PayPal charged automatically, or paid from the invoice):
     * the gateway's name, then the saved method that would charge its renewals with it, or «paid from the invoice». Null:
     * the account's way. Only a label of the way (not of the item: a suspended service is still labelled by its way): the
     * «Automatic charge» line under it (autoChargeStatus) says what happens now.
     */
    public static function optionLabel(Service|Domain $item, ?Gateway $gateway): string
    {
        $name = $gateway === null ? __('services.payment_method_account_short') : (string) $gateway->name;
        if ($gateway !== null && !$gateway->enabled) {
            return __('services.payment_method_option_unavailable', ['gateway' => $name]); // kept as its current one, not offered
        }
        $agreement = $item->no_auto_charge ? null : $item->client?->autoChargeAgreementFor($gateway === null ? null : (int) $gateway->id);
        $agreement = $agreement?->stopped() ? null : $agreement; // as the cron (review of #572)

        return $agreement !== null
            ? __('services.payment_method_option_auto', ['gateway' => $name, 'method' => $agreement->name])
            : __('services.payment_method_option_manual', ['gateway' => $name]);
    }

    /**
     * What its page tells the customer and staff (owner 2026-11-01: no misunderstanding): charged automatically with which
     * method, or why not, by the cron's rules (AutoCharge::due and agreementFor; review 2 of #568: a suspended service, a
     * domain not renewing, a method stopped at its failures, an open invoice held by another item on it; C6 of #695: a
     * cancellation requested, of it or of another service on its open invoice).
     */
    public static function autoChargeStatus(Service|Domain $item): string
    {
        $status = fn (string $key, array $with = []) => __("services.auto_charge_status.{$key}", $with);
        if ($item instanceof Service && (string) $item->subscription_id !== '') {
            return $status('subscription');
        }
        if ($item->no_auto_charge) {
            return $status('off');
        }
        if ($item instanceof Service && $item->status !== Service::STATUS_ACTIVE) {
            return $status('not_active');
        }
        // Its cancellation asked (ADV-A2-1, AutoCharge::cancellationLines): its renewal is never charged (C6 of #695).
        if ($item instanceof Service && $item->cancellation()->exists()) {
            return $status('cancellation');
        }
        if ($item instanceof Domain && (!$item->auto_renew || in_array($item->status->value, DomainStatus::terminalValues(), true))) {
            return $status('not_renewing');
        }
        // Its open invoice is charged first: the page says what the cron will do with it, and why not (ADV-568-R2-6). It
        // follows a change of its payment method (followOnOpenInvoices), unless a charge of it is on its way.
        $open = self::openInvoice($item);
        if ($open !== null) {
            $agreement = AutoCharge::willCharge($open) ? AutoCharge::agreementFor($open) : null; // the cron's own rules (SW-2)
            if ($agreement !== null) {
                return $status('charged', ['method' => $agreement->name]);
            }
            if ($open->no_auto_charge) {
                return $status('invoice_off', ['invoice' => $open->id]);
            }
            if ($open->items()->where(fn ($lines) => self::kept($lines))->exists()) {
                return $status('held', ['invoice' => $open->id]);
            }
            if ($open->items()->where(fn ($lines) => AutoCharge::cancellationLines($lines))->exists()) {
                return $status('held_cancellation', ['invoice' => $open->id]); // another service's (C7 of #695)
            }
        }
        $client = $item->client;
        if ($client === null || !$client->auto_charge) {
            return $status('account_off');
        }
        $default = $client->autoChargeMethod();
        if ($default === null) {
            return $status('no_default');
        }
        $own = self::live($item);
        if ($own !== null && $own !== (int) $default->gateway_id) {
            $gateway = Gateway::query()->find($own);
            if ($gateway !== null && !self::savesMethods($gateway)) {
                return $status('manual', ['gateway' => $gateway->name]);
            }
            $agreement = $client->autoChargeMethodFor($own);
            if ($agreement === null) {
                return $status('no_method', ['gateway' => (string) $gateway?->name]);
            }
        } else {
            $agreement = $default;
        }
        if (!ExtensionHelper::gatewayEnabled($agreement->gateway_id)) {
            return $status('gateway_off', ['gateway' => (string) Gateway::query()->withTrashed()->whereKey($agreement->gateway_id)->value('name')]);
        }
        if ($agreement->stopped()) {
            return $status('stopped', ['method' => $agreement->name]);
        }
        if ($open !== null) {
            // Its next renewal would be; the open one is not (a confirmation the bank asked for, lines of two ways).
            return $status('open_manual', ['invoice' => $open->id]);
        }

        return $status('charged', ['method' => $agreement->name]);
    }

    /**
     * The methods that charge the services and domains paid with another gateway than the default's (owner 2026-11-01):
     * agreement id => that gateway's name, as AutoCharge charges them (Client::autoChargeAgreementFor). What the customer's
     * Payment Methods page and the ops tools (Ops\Charges) say each method pays.
     *
     * @return array<int, string>
     */
    public static function ownWayMethods(Client $client): array
    {
        $default = $client->autoChargeMethod();
        $methods = [];
        foreach ($client->billingAgreements()->with('gateway')->get()->pluck('gateway')->filter()->unique('id') as $gateway) {
            if ($default !== null && (int) $default->gateway_id === (int) $gateway->id) {
                continue;
            }
            $paid = Service::query()->where('client_id', $client->id)->where('payment_gateway_id', $gateway->id)->where('no_auto_charge', false)
                ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])->exists()
                || Domain::query()->where('client_id', $client->id)->where('payment_gateway_id', $gateway->id)->where('no_auto_charge', false)
                    ->whereNotIn('status', DomainStatus::terminalValues())->exists();
            $agreement = $paid ? $client->autoChargeAgreementFor((int) $gateway->id) : null;
            if ($agreement !== null) {
                $methods[(int) $agreement->id] = (string) $gateway->name;
            }
        }

        return $methods;
    }

    /** Its open invoice the cron would charge next: the earliest due, unpaid, with a line of it. */
    private static function openInvoice(Service|Domain $item): ?Invoice
    {
        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)
            ->whereIn('id', InvoiceItem::query()->select('invoice_id')->where('reference_type', $item::class)->where('reference_id', $item->id))
            ->orderBy('due_at')->orderBy('id')->first();
    }

    /**
     * The lines of a service or domain set «Do not charge automatically» (#568), as they are now.
     *
     * @param  Builder<InvoiceItem>  $lines
     * @return Builder<InvoiceItem>
     */
    public static function kept(Builder $lines): Builder
    {
        return $lines->where(fn ($line) => $line->where('reference_type', Service::class)->whereIn('reference_id', Service::query()->select('id')->where('no_auto_charge', true)))
            ->orWhere(fn ($line) => $line->where('reference_type', Domain::class)->whereIn('reference_id', Domain::query()->select('id')->where('no_auto_charge', true)));
    }

    /** A gateway that saves a payment method to charge later (Stripe, PayPal); a bank transfer or a vPOS redirect does not. */
    private static function savesMethods(Gateway $gateway): bool
    {
        return (bool) rescue(fn () => ExtensionHelper::hasFunction($gateway, 'supportsBillingAgreements')
            && ExtensionHelper::getExtension('gateway', $gateway->extension, $gateway->settings)->supportsBillingAgreements(), false, false);
    }

    /** Its own «Do not charge automatically» (#568), audited: its open invoices too (keepApart, from the observers). */
    public static function setAutoCharge(Service|Domain $item, bool $on): void
    {
        if ($item->no_auto_charge === !$on) {
            return;
        }
        self::$leftWhole = [];
        $item->update(['no_auto_charge' => !$on]);
    }

    /**
     * Its payment method changed (owner 2026-11-01, #568: the open invoice too, no longer «from the next renewal» of #541):
     * the lines of its open invoices take the new way, and the invoice its preferred method when it bills only this item
     * (with its lines of no item, a fee: ADV-568-R3-2). One it shares with items of another way now is split, its lines
     * to an invoice of their own (SplitInvoice). One it shares that cannot be split (it is Ekdosi's, a payment on it:
     * InvoiceLineMove::refusal, asked BEFORE a line changes, REV-778-4) stays exactly as it is, of one way, charged as it
     * was: the new method is for the item's next invoices, and the customer is told (notice()). Under the customer → invoice locks, as a split (ADV-568-R3 suspicion 1), each
     * line through the model, audited (R3-3). Never while a charge of the invoice may still land (chargePending): it keeps
     * the way that charge was made with, so another method is never sent a second charge of it.
     */
    public static function followOnOpenInvoices(Service|Domain $item): void
    {
        $own = self::live($item);
        $open = InvoiceItem::query()->where('reference_type', $item::class)->where('reference_id', $item->id)
            ->whereIn('invoice_id', Invoice::query()->select('id')->where('status', Invoice::STATUS_UNPAID))->distinct()->pluck('invoice_id');
        foreach ($open as $invoiceId) {
            $apart = DB::transaction(function () use ($invoiceId, $item, $own) {
                $invoice = InvoiceLineMove::lock([(int) $invoiceId])->sole();
                if ($invoice->status !== Invoice::STATUS_UNPAID || self::chargePending($invoice)) {
                    return null;
                }
                $mine = InvoiceItem::query()->where('invoice_id', $invoice->id)->where('reference_type', $item::class)->where('reference_id', $item->id);
                $shared = InvoiceItem::query()->where('invoice_id', $invoice->id)->whereIn('reference_type', [Service::class, Domain::class])
                    ->whereNot(fn ($lines) => $lines->where('reference_type', $item::class)->where('reference_id', $item->id))->exists();
                if ($shared && InvoiceLineMove::refusal($invoice) !== null) {
                    self::$leftWhole[] = '#' . ($invoice->number ?: $invoice->id);

                    return null;
                }
                // The way only (not a money field): no amount, tax or period moves.
                ($shared ? (clone $mine) : InvoiceItem::query()->where('invoice_id', $invoice->id))->get()
                    ->each(fn (InvoiceItem $line) => $line->update(['payment_gateway_id' => $own]));
                $preferred = $own ?? Invoice::savedMethodGatewayFor((int) $invoice->client_id);
                if (!$shared) {
                    $invoice->forceFill(['preferred_gateway_id' => $preferred])->save();

                    return null;
                }

                return AutoCharge::waysMixed($invoice->fresh()) ? [$invoice->id, $mine->pluck('id')->map(fn ($id) => (int) $id)->all(), $preferred] : null;
            });
            if ($apart === null) {
                continue;
            }
            try {
                (new SplitInvoice)->handle($apart[0], $apart[1])->forceFill(['preferred_gateway_id' => $apart[2]])->save();
            } catch (DisplayException) {
                // Stays whole, of two ways, which no method charges (a part of nothing, an upgrade's lines): told as the others.
                self::$leftWhole[] = '#' . (Invoice::query()->whereKey($apart[0])->value('number') ?: $apart[0]);
            }
        }
    }

    /**
     * A charge of it that may still land: not settled (SavedMethodCharge::onItsWay: on its way, unknown, taken and not
     * recorded, however long: predeploy S1), or one the bank asked the customer to confirm within its key window
     * (ADV-568-R3-1).
     */
    private static function chargePending(Invoice $invoice): bool
    {
        return SavedMethodCharge::onItsWay($invoice) || ChargeAttempt::query()->where('invoice_id', $invoice->id)
            ->where('status', ChargeAttempt::STATUS_ACTION_REQUIRED)
            ->where('started_at', '>', now()->subHours(SavedMethodCharge::KEY_REUSE_HOURS))->exists();
    }

    /**
     * Turned off (review 2 of #568): its lines leave an open invoice they share with items still charged, for an invoice of
     * their own (SplitInvoice), so the cron still charges the others. One that cannot be split (a payment on it, a discount
     * that would be left alone, one that is Ekdosi's) stays whole and is not charged automatically: autoChargeStatus says so
     * on the others, and the customer is told when they turn it off (notice(), REV-778-4).
     */
    public static function keepApart(Service|Domain $item): void
    {
        $open = InvoiceItem::query()->where('reference_type', $item::class)->where('reference_id', $item->id)
            ->whereIn('invoice_id', Invoice::query()->select('id')->where('status', Invoice::STATUS_UNPAID))->distinct()->pluck('invoice_id');
        foreach ($open as $invoiceId) {
            $mine = InvoiceItem::query()->where('invoice_id', $invoiceId)->where('reference_type', $item::class)->where('reference_id', $item->id)->pluck('id');
            $charged = InvoiceItem::query()->where('invoice_id', $invoiceId)->whereNotIn('id', $mine)->whereIn('reference_type', [Service::class, Domain::class])
                ->whereNot(fn ($lines) => self::kept($lines))->exists();
            if (!$charged) {
                continue; // alone, or with others kept too: held as it is
            }
            try {
                (new SplitInvoice)->handle((int) $invoiceId, $mine->map(fn ($id) => (int) $id)->all());
            } catch (DisplayException) {
                // Stays whole, held: its others are paid from it (their page says so).
                self::$leftWhole[] = '#' . (Invoice::query()->whereKey($invoiceId)->value('number') ?: $invoiceId);
            }
        }
    }
}
