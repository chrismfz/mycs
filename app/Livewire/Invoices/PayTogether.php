<?php

namespace App\Livewire\Invoices;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGroup;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\PaymentReminders;
use App\Services\Payment\SavedMethodCharge;
use App\Support\Actor;
use App\Support\PayOptions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Mass Pay (PR F2): the invoices the customer chose, paid at once through a gateway or a saved payment method. The
 * gateway is opened on the invoice due first for the whole amount and returns there; the payment pays the others as
 * it lands (MassPay::spread). Account credit is not offered here: each invoice takes it on its own page. The ways to pay
 * are the invoice page's panel, by provider (docs/pay-flow-ux.md, PR 4).
 *
 * @property-read array<int, Gateway> $gateways
 * @property-read Collection<int, BillingAgreement> $savedPaymentMethods
 * @property-read list<array{gateway: Gateway, saved: list<BillingAgreement>, oneTime: bool}> $payGroups
 * @property-read int|null $automaticMethodId
 * @property-read string $payLabel
 * @property-read bool $held
 * @property-read list<int> $heldInvoices
 * @property-read string|null $staleness
 * @property-read bool $otherWaysElsewhere
 */
class PayTogether extends Component
{
    #[Locked]
    public PaymentGroup $paymentGroup;

    public $selectedMethod = null;

    private $pay = null;

    public function mount()
    {
        $this->paymentGroup->load('invoices', 'invoice', 'client');
        // The account's automatic-payments method chosen to start with, when it may pay the group (as the invoice's page
        // chooses its preferred way): the button names it, nothing is charged before it is pressed. Not when each invoice
        // is one the cron charges on its own: the invoice's page chooses nothing then either (ADV-599-1).
        // Nor when the bank last asked the customer to confirm a charge of it for one of the invoices (3-D Secure, which
        // AutoCharge::agreementFor skips too): charged again, it would ask again.
        $ownWay = $this->paymentGroup->invoices->every(fn (Invoice $invoice) => PaymentReminders::chargedOnItsOwn($invoice));
        $automatic = $ownWay || $this->automaticMethodId === null || $this->askedToConfirm($this->automaticMethodId) ? null : $this->automaticMethodId;
        $this->selectedMethod = $automatic === null ? null : $this->savedPaymentMethods->firstWhere('id', $automatic)?->ulid;
    }

    /**
     * Whether the last charge of this method that paid one of the group's invoices is one the bank asked to confirm: of
     * the invoice itself, or of a group it is in (a group's charge sits on its first invoice only: review of #602).
     */
    private function askedToConfirm(int $agreementId): bool
    {
        $ids = $this->paymentGroup->invoices->map(fn (Invoice $invoice) => (int) $invoice->id)->all();
        $groupsOf = DB::table('payment_group_invoice')->whereIn('invoice_id', $ids)->get(['payment_group_id', 'invoice_id'])
            ->groupBy('payment_group_id')->map(fn ($rows) => $rows->pluck('invoice_id')->map(fn ($id) => (int) $id)->all());
        $attempts = ChargeAttempt::query()->where('billing_agreement_id', $agreementId)
            ->where(fn ($query) => $query->whereIn('invoice_id', $ids)->orWhereIn('payment_group_id', $groupsOf->keys()))
            ->orderBy('id')->get(['id', 'invoice_id', 'payment_group_id', 'status']);
        $last = [];
        foreach ($attempts as $attempt) {
            $covers = $attempt->payment_group_id !== null && $groupsOf->has($attempt->payment_group_id)
                ? $groupsOf->get($attempt->payment_group_id) : [(int) $attempt->invoice_id];
            foreach (array_intersect($covers, $ids) as $id) {
                $last[$id] = $attempt->status;
            }
        }

        return in_array(ChargeAttempt::STATUS_ACTION_REQUIRED, $last, true);
    }

    #[Computed]
    public function staleness(): ?string
    {
        return MassPay::staleness($this->paymentGroup->fresh());
    }

    #[Computed]
    public function gateways()
    {
        $items = $this->paymentGroup->invoices->flatMap(fn (Invoice $invoice) => $invoice->items);

        // Only a gateway that carries the group to the provider and back: its payment is the one spread.
        return array_values(array_filter(ExtensionHelper::getCheckoutGateways((float) $this->paymentGroup->total, $this->paymentGroup->currency_code, 'invoice', $items),
            fn (Gateway $gateway) => ExtensionHelper::supportsPaymentGroups($gateway)));
    }

    #[Computed]
    public function savedPaymentMethods()
    {
        $usable = array_column(array_filter(ExtensionHelper::getBillingAgreementGateways(), fn (Gateway $gateway) => ExtensionHelper::supportsPaymentGroups($gateway)), 'id');

        return BillingAgreement::query()->where('client_id', $this->paymentGroup->client_id)->whereIn('gateway_id', $usable)->with('gateway')->get();
    }

    /** The ways to pay the group, by provider (PayOptions): the same methods and gateways as before. */
    #[Computed]
    public function payGroups(): array
    {
        // Ordered as on the invoice's page: the account's default first (review of #599).
        return PayOptions::byProvider($this->savedPaymentMethods, $this->gateways, $this->paymentGroup->client?->default_billing_agreement_id);
    }

    /** The saved method that pays the account's renewals now (as the invoice's page marks it). */
    #[Computed]
    public function automaticMethodId(): ?int
    {
        $agreement = $this->paymentGroup->client?->autoChargeAgreement();

        return $agreement !== null && !$agreement->stopped() ? (int) $agreement->id : null;
    }

    /** What the pay button says for the way chosen, with the group's total. */
    #[Computed]
    public function payLabel(): string
    {
        $amount = number_format((float) $this->paymentGroup->total, 2) . ' ' . $this->paymentGroup->currency_code;
        $chosen = $this->chosen();
        if ($chosen !== null && str_starts_with($chosen, 'gateway-')) {
            $gateway = collect($this->gateways)->firstWhere('id', (int) substr($chosen, 8));
            if ($gateway !== null) {
                return __('invoices.pay_panel.continue_with', ['gateway' => $gateway->name, 'amount' => $amount]);
            }
        }
        $agreement = $chosen === null ? null : $this->savedPaymentMethods->firstWhere('ulid', '===', $chosen);

        return $agreement !== null ? __('invoices.pay_panel.pay_with', ['amount' => $amount, 'method' => $agreement->name])
            : __('invoices.pay_amount', ['amount' => $amount]);
    }

    /**
     * The group's invoices with a payment on its way (a saved method's charge not settled, a payment the gateway processes:
     * the group's, or one of its own, SavedMethodCharge::onItsWay), unless the group is paid: told as such, not as
     * «changed» or «chosen again», which choosing again could not fix (a replaced group's own charge: ADV-599-2). All of
     * them: nothing can be paid until it settles; some: the others can be chosen again without them (review of #599).
     * Each invoice read, so a deleted lead hides nothing.
     *
     * @return list<int>
     */
    #[Computed]
    public function heldInvoices(): array
    {
        if ($this->paymentGroup->status === PaymentGroup::STATUS_PAID) {
            return [];
        }

        return $this->paymentGroup->invoices->filter(fn (Invoice $invoice) => SavedMethodCharge::onItsWay($invoice))
            ->map(fn (Invoice $invoice) => (int) $invoice->id)->values()->all();
    }

    #[Computed]
    public function held(): bool
    {
        return $this->heldInvoices !== [];
    }

    /**
     * Ways to pay these invoices that this page does not offer: the balance, or a gateway that takes no group payment
     * (a bank transfer). The note under the choices points to each invoice's page only then (review of #599).
     */
    #[Computed]
    public function otherWaysElsewhere(): bool
    {
        $credit = config('settings.credits_enabled') && Credit::query()->where('client_id', $this->paymentGroup->client_id)
            ->where('currency_code', $this->paymentGroup->currency_code)->where('amount', '>', 0)->exists();
        $items = $this->paymentGroup->invoices->flatMap(fn (Invoice $invoice) => $invoice->items);

        return $credit || count(ExtensionHelper::getCheckoutGateways((float) $this->paymentGroup->total, $this->paymentGroup->currency_code, 'invoice', $items)) > count($this->gateways);
    }

    /** The way chosen, when it is one: a crafted update sets anything (as the invoice's page). */
    private function chosen(): ?string
    {
        return is_string($this->selectedMethod) && $this->selectedMethod !== '' ? $this->selectedMethod : null;
    }

    public function processPayment()
    {
        // Staff in «Login as client» spend or change the customer's own money (a saved card, the vault, the balance) only
        // with the admin permission the same change needs there (security audit SA-5).
        if (Actor::impersonating() && !Actor::staffMayAs('pay')) {
            return $this->notify(__('account.notifications.not_while_impersonating_money'), 'error');
        }
        // Read again as the gateway opens: staff may have changed an invoice, or the customer chosen again elsewhere.
        $this->paymentGroup->refresh();
        if (($stale = MassPay::staleness($this->paymentGroup)) !== null) {
            return $this->notify($stale, 'error');
        }
        if ($this->chosen() === null) {
            return; // none, or a crafted value no choice holds
        }

        if (str_starts_with($this->selectedMethod, 'gateway-')) {
            $gatewayId = substr($this->selectedMethod, 8);
            if (!in_array($gatewayId, array_map('strval', array_column($this->gateways(), 'id')), true)) {
                return $this->notify(__('Invalid payment method.'), 'error');
            }
            // Held as each invoice's page holds it (a charge of one of them on its way), and stamped on them all under the
            // payment locks: the cron does not charge one of them while the group's checkout may still pay it (sweep 3
            // L-NO-DOUBLE-CHARGE-2).
            if (!SavedMethodCharge::openCheckout($this->paymentGroup->invoice, $this->paymentGroup)) {
                return $this->notify(__('A payment for these invoices is already on its way.'), 'error');
            }
            $this->pay = ExtensionHelper::payGroup(Gateway::query()->findOrFail($gatewayId), $this->paymentGroup);
            if (is_string($this->pay)) {
                $this->redirect($this->pay);
            }

            return;
        }

        $agreement = $this->savedPaymentMethods()->firstWhere('ulid', '===', $this->chosen());
        if ($agreement === null || $agreement->gateway === null) {
            return $this->notify(__('Invalid payment method.'), 'error');
        }
        // A saved method is charged at once: a second click (or tab) before the payment is recorded would charge the total
        // twice. The charge attempt holds the group's first invoice under the payment locks (PR H, replacing F2's cache
        // lock): a declined one frees it to try again.
        try {
            $attempt = (new SavedMethodCharge)->charge($this->paymentGroup->invoice, $agreement, ChargeAttempt::SOURCE_GROUP, $this->paymentGroup);
        } catch (DisplayException $e) {
            return $this->notify($e->getMessage(), 'error');
        }
        if ($attempt->status === ChargeAttempt::STATUS_SUCCEEDED) {
            $this->notify(__('Successfully charged the saved payment method.'), 'success');

            return $this->redirect(route('invoices.show', $this->paymentGroup->invoice) . '?checkPayment', true);
        }
        if ($attempt->status === ChargeAttempt::STATUS_UNKNOWN) {
            return $this->notify(__('We could not confirm the payment with your bank yet. Please check this invoice again in a few minutes before paying again.'), 'error');
        }
        if ($attempt->status === ChargeAttempt::STATUS_ACTION_REQUIRED) {
            return $this->notify(__('Your bank asks you to confirm this payment: please pay these invoices with your card instead.'), 'error');
        }

        return $this->notify(__('Could not process payment. Please try again or use a different payment method.'), 'error');
    }

    public function exitPay()
    {
        $this->pay = null;
        $this->dispatch('invoice.payment.cancelled', $this->paymentGroup->invoice);
        $this->redirect(route('invoices.pay-together', $this->paymentGroup), true);
    }

    public function render()
    {
        return view('invoices.pay-together', ['pay' => $this->pay])->layoutData([
            'title' => __('invoices.mass_pay_title'),
            'sidebar' => true,
        ]);
    }
}
