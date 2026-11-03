<?php

namespace App\Livewire\Invoices;

use App\Classes\PDF;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\Invoice\PaymentReminders;
use App\Services\Invoice\PaymentStatus;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use App\Support\Actor;
use App\Support\PayOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * The customer's invoice page: its status card (PaymentStatus) and its ways to pay (PayOptions, docs/pay-flow-ux.md).
 *
 * @property-read array<int, Gateway> $gateways
 * @property-read array<int, Gateway> $paymentMethods
 * @property-read Collection<int, BillingAgreement> $savedPaymentMethods
 * @property-read list<array{gateway: Gateway, saved: list<BillingAgreement>, oneTime: bool}> $payGroups
 * @property-read Credit|null $usableCredit
 * @property-read int|null $automaticMethodId
 * @property-read string $payLabel
 * @property-read string|null $renewalsChoice
 */
class Show extends Component
{
    #[Locked]
    public Invoice $invoice;

    public $checkPayment = false;

    private $pay = null;

    #[Url('pay', except: false, nullable: true)]
    public $showPayModal = false;

    public $lastChecked = null;

    public $selectedMethod = null;

    public $setAsDefault = false;

    public function mount()
    {
        if (Request::has('checkPayment') && $this->invoice->status === Invoice::STATUS_UNPAID) {
            $this->checkPayment = true;
        }
        // A payment the gateway processes (SEPA can take days) is shown and looked at again by the status card, every 30
        // seconds and rendered (review of #593): no longer the 5-second check, which skipped rendering until it was paid.

        // Load relations
        $this->invoice->load('transactions', 'transactions.gateway', 'transactions.invoice', 'adjustmentNotes', 'ekdosiDocuments');

        // Its window is not opened (an email's ?pay=1) for one that cannot be paid now: paid, or a payment on its way that the
        // server would refuse (docs/pay-flow-ux.md, PR 2).
        if ($this->showPayModal && !$this->status()->payable()) {
            $this->showPayModal = false;
        }
        $this->selectedMethod ??= $this->preferredChoice();
    }

    /**
     * Its preferred payment method, chosen to start with (WHMCS's invoice payment method): the account's saved method when
     * it is on that gateway and may pay, else the gateway when this invoice can use it; else none. The customer may pick
     * another.
     */
    private function preferredChoice(): ?string
    {
        $gatewayId = $this->invoice->preferred_gateway_id;
        // Paid on its own: nothing chosen for it (a bank transfer would refuse it: R3-1 of #539).
        if ($gatewayId === null || $this->invoice->status !== Invoice::STATUS_UNPAID || PaymentReminders::chargedOnItsOwn($this->invoice)) {
            return null;
        }
        $default = $this->accountAgreements()->whereKey($this->invoice->client->default_billing_agreement_id ?? 0)->where('gateway_id', $gatewayId)->first();
        if ($default !== null && in_array($gatewayId, array_column($this->paymentMethods(), 'id'))) {
            return $default->ulid;
        }

        return in_array($gatewayId, array_column($this->gateways(), 'id')) ? 'gateway-' . $gatewayId : null;
    }

    /** The way the customer chose to pay, kept as its preferred one (as WHMCS keeps the one picked), in the audit. */
    private function remember(int $gatewayId): void
    {
        if ((int) $this->invoice->preferred_gateway_id !== $gatewayId) {
            Invoice::query()->findOrFail($this->invoice->id)->forceFill(['preferred_gateway_id' => $gatewayId])->save();
            $this->invoice->preferred_gateway_id = $gatewayId;
        }
    }

    #[Computed]
    public function gateways()
    {
        return ExtensionHelper::getCheckoutGateways($this->invoice->total, $this->invoice->currency_code, 'invoice', $this->invoice->items);
    }

    #[Computed]
    public function paymentMethods()
    {
        return ExtensionHelper::getBillingAgreementGateways();
    }

    #[Computed]
    public function savedPaymentMethods()
    {
        // Only those of a gateway customers may pay through: a disabled gateway's methods are kept, not offered; nor those of
        // a gateway that saves no methods now (PayPal's vault turned off), which payWithSavedMethod refuses (ADV-597-1).
        return $this->accountAgreements()->whereHas('gateway', fn ($gateway) => $gateway->where('enabled', true))
            ->whereIn('gateway_id', array_column($this->paymentMethods, 'id'))->with('gateway')->get();
    }

    /**
     * The invoice's account's saved payment methods, whichever account the client area is on: one account's card
     * never pays, nor is set on the services of, another's invoice (docs/accounts.md, PR 2a).
     *
     * @return Builder<BillingAgreement>
     */
    private function accountAgreements()
    {
        return BillingAgreement::query()->where('client_id', $this->invoice->client_id ?? 0);
    }

    #[Computed]
    public function recurringServices()
    {
        return $this->invoice->items()
            ->where('reference_type', Service::class)
            ->whereNotNull('reference_id')
            ->where(fn ($line) => $line->whereNull('kind')->orWhere('kind', '!=', InvoiceItem::KIND_CHARGE))
            ->whereHasMorph('reference', [Service::class], function ($query) {
                $query->whereHas('plan', function ($planQuery) {
                    $planQuery->whereNotIn('type', ['one-time', 'free']);
                });
            });
    }

    /** The ways to pay it, by provider (PayOptions, docs/pay-flow-ux.md PR 3): the same methods and gateways as before. */
    #[Computed]
    public function payGroups(): array
    {
        return PayOptions::byProvider($this->savedPaymentMethods, $this->gateways, $this->invoice->client?->default_billing_agreement_id);
    }

    /** The account's balance in its currency, when it may pay it (as the old window offered it), else null. */
    #[Computed]
    public function usableCredit(): ?Credit
    {
        if (!config('settings.credits_enabled') || $this->invoice->items->contains(fn ($item) => $item->reference_type === Credit::class)) {
            return null;
        }

        return Credit::query()->where('client_id', $this->invoice->client_id ?? 0)->where('currency_code', $this->invoice->currency_code)
            ->where('amount', '>', 0)->first();
    }

    /** What the pay button says for the way chosen. */
    #[Computed]
    public function payLabel(): string
    {
        $amount = $this->invoice->formattedRemaining;
        $credit = $this->usableCredit;
        $chosen = $this->chosen();
        if ($chosen === 'credit' && $credit !== null) {
            return $credit->amount >= $this->invoice->formattedRemaining->total
                ? __('invoices.apply_credits_and_pay')
                : __('invoices.apply_credit_and_continue', ['amount' => $credit->formattedAmount]);
        }
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
     * The «future renewals» toggle (setAsDefault) for the saved method chosen: «toggle» when it would change something,
     * «already» when that method already pays the account's renewals and this one too, null when it does not apply: the
     * account's method, while another way pays this invoice's renewal (its service paid with PayPal, say: AutoCharge::
     * agreementFor), is neither (ADV-597-2).
     */
    #[Computed]
    public function renewalsChoice(): ?string
    {
        $chosen = $this->chosen();
        $agreement = $chosen === null ? null : $this->savedPaymentMethods->firstWhere('ulid', '===', $chosen);
        if ($agreement === null || !$this->recurringServices()->exists()) {
            return null;
        }
        if ($this->automaticMethodId !== (int) $agreement->id) {
            return 'toggle';
        }

        return AutoCharge::agreementFor($this->invoice)?->id === $agreement->id ? 'already' : null;
    }

    /** The way chosen, when it is one: a crafted update sets anything (ADV-597-5). */
    private function chosen(): ?string
    {
        return is_string($this->selectedMethod) && $this->selectedMethod !== '' ? $this->selectedMethod : null;
    }

    /**
     * The saved method that pays the account's renewals now, as the cron reads it (Client::autoChargeAgreement: «Auto-charge»
     * on, its default, its gateway enabled; not stopped at autocharge_max_failures): the one marked, and the one for which
     * the renewals toggle would change nothing (review of #597).
     */
    #[Computed]
    public function automaticMethodId(): ?int
    {
        $agreement = $this->invoice->client?->autoChargeAgreement();

        return $agreement !== null && !$agreement->stopped() ? (int) $agreement->id : null;
    }

    /** Another way chosen: the renewals toggle starts off again, so it never applies to a method it was not shown for. */
    public function updatedSelectedMethod(): void
    {
        $this->setAsDefault = false;
    }

    public function updatedShowPayModal($value)
    {
        if ($value && !$this->status()->payable()) {
            $this->showPayModal = false;
        }
    }

    /** What its page tells the customer (PaymentStatus): read once a request, again after the invoice is read again. */
    private ?PaymentStatus $statusMemo = null;

    private function status(): PaymentStatus
    {
        return $this->statusMemo ??= PaymentStatus::for($this->invoice, (bool) $this->checkPayment);
    }

    public function processPayment()
    {
        // Staff in «Login as client» spend or change the customer's own money (a saved card, the vault, the balance) only
        // with the admin permission the same change needs there (security audit SA-5).
        if (Actor::impersonating() && !Actor::staffMayAs('pay')) {
            return $this->notify(__('account.notifications.not_while_impersonating_money'), 'error');
        }
        if ($this->invoice->status !== Invoice::STATUS_UNPAID) {
            return $this->notify(__('This invoice cannot be paid.'), 'error');
        }
        // Left open for staff (it holds gateway money): paying it would buy nothing, so neither credit nor a gateway.
        if ($this->invoice->billsEndedService()) {
            return $this->notify(__('This invoice bills a service that has ended: please contact us.'), 'error');
        }

        if ($this->chosen() === null) {
            return; // none, or a crafted value no choice holds (ADV-597-5)
        }

        if ($this->selectedMethod === 'credit') {
            if (!config('settings.credits_enabled')) {
                return $this->notify(__('Paying with credits is currently disabled.'), 'error');
            }
            if ($this->invoice->items->contains(fn ($item) => $item->reference_type === Credit::class)) {
                return $this->notify(__('This invoice cannot be paid with credits.'), 'error');
            }
            // A charge or a payment on its way holds it for every way of paying (docs/payments.md, predeploy S1): the
            // balance too, read under its payment lock as a gateway's is (adversarial ADV-593-3).
            if (DB::transaction(fn () => SavedMethodCharge::onItsWay((new RecordPaymentService)->lockInvoice($this->invoice->id)))) {
                return $this->notify(__('A payment for this invoice is already on its way.'), 'error');
            }

            return $this->payWithCredit();
        }

        if (str_starts_with($this->selectedMethod, 'gateway-')) {
            $gatewayId = substr($this->selectedMethod, 8);

            return $this->payWithMethod($gatewayId);
        }

        return $this->payWithSavedMethod($this->selectedMethod);
    }

    private function payWithMethod($methodId)
    {
        if (!in_array($methodId, array_column($this->gateways, 'id'))) {
            return $this->notify(__('Invalid payment method.'), 'error');
        }
        // A charge of a saved method not settled (on its way, unknown, taken and not recorded): another checkout could take
        // the same debt again (predeploy S2). Read under the invoice's payment lock, as SavedMethodCharge does.
        $held = DB::transaction(fn () => SavedMethodCharge::onItsWay((new RecordPaymentService)->lockInvoice($this->invoice->id)));
        if ($held) {
            return $this->notify(__('A payment for this invoice is already on its way.'), 'error');
        }

        $this->pay = ExtensionHelper::pay(Gateway::where('id', $methodId)->first(), $this->invoice);
        // Opened (its page, its window): the way chosen, kept; a refusal changes nothing (ADV-539-4), nor one paid on its own
        // (a saved method or a subscription: the bank transfer's «charged» page is a refusal, R2-1 of #539).
        if ($this->pay !== null && $this->pay !== false && !PaymentReminders::chargedOnItsOwn($this->invoice)) {
            $this->remember((int) $methodId);
        }

        if (is_string($this->pay)) {
            $this->redirect($this->pay);
        }
    }

    private function payWithCredit()
    {
        // Read and spend the credit under the payment locks (RecordPaymentService): never a stale copy.
        $transaction = (new RecordPaymentService)->payWithCredit($this->invoice);
        $this->invoice = $this->invoice->fresh();
        $this->statusMemo = null;
        if ($transaction === null) {
            return;
        }
        if ($this->invoice->status === Invoice::STATUS_PAID) {
            return $this->redirect(route('invoices.show', $this->invoice), true);
        }
        // The balance is spent: its choice is gone, the way it would open with is chosen again (ADV-597-4).
        $this->selectedMethod = $this->preferredChoice();
        $this->statusMemo = null;
        $this->notify(__('Part of the invoice has been paid with credits. Please pay the remaining amount'));
    }

    private function payWithSavedMethod($agreementUlid)
    {
        $agreement = $this->accountAgreements()->where('ulid', $agreementUlid)->with('gateway')->first();
        if (!$agreement) {
            return $this->notify(__('Invalid payment method.'), 'error');
        }

        if ($agreement->gateway === null || !in_array($agreement->gateway->id, array_column($this->paymentMethods, 'id'))) {
            return $this->notify(__('This payment method cannot be used for this invoice.'), 'error');
        }

        // The account's method for its renewals (decision 6), set by a login that may pay its invoices: one that can pay.
        if ($this->setAsDefault && $this->recurringServices()->exists()) {
            $this->invoice->client->update(['default_billing_agreement_id' => $agreement->id, 'auto_charge' => true]);
            $this->notify(__('Default payment method has been updated for recurring services.'), 'success');
        }

        // One charge at a time, under the payment locks, with its idempotency key (PR H, PAY-3): a second click, another
        // tab or the cron while this one is on its way is refused.
        try {
            $attempt = (new SavedMethodCharge)->charge($this->invoice, $agreement, ChargeAttempt::SOURCE_CLIENT);
        } catch (DisplayException $e) {
            return $this->notify($e->getMessage(), 'error');
        }

        if ($attempt->status === ChargeAttempt::STATUS_SUCCEEDED) {
            $this->remember((int) $agreement->gateway->id); // paid with it: kept (a refused charge changes nothing)
            $this->notify(__('Successfully charged the saved payment method.'), 'success');

            return $this->redirect(route('invoices.show', $this->invoice) . '?checkPayment', true);
        }
        if ($attempt->status === ChargeAttempt::STATUS_UNKNOWN) {
            return $this->notify(__('We could not confirm the payment with your bank yet. Please check this invoice again in a few minutes before paying again.'), 'error');
        }
        if ($attempt->status === ChargeAttempt::STATUS_ACTION_REQUIRED) {
            return $this->notify(__('Your bank asks you to confirm this payment: please pay the invoice with your card instead.'), 'error');
        }

        return $this->notify(__('Could not process payment. Please try again or use a different payment method.'), 'error');
    }

    public function exitPay()
    {
        $this->pay = null;
        // Dispatch event so extensions can do their thing
        $this->dispatch('invoice.payment.cancelled', $this->invoice);
        // Refresh invoice status
        $this->redirect(route('invoices.show', $this->invoice), true);
    }

    public function checkPaymentStatus()
    {
        $this->invoice->refresh();
        $this->statusMemo = null;

        // Check for transactions that failed since lastChecked
        if ($this->lastChecked) {
            $failedSinceLastCheck = $this->invoice->transactions()
                ->where('status', InvoiceTransactionStatus::Failed)
                ->where('updated_at', '>', $this->lastChecked)
                ->exists();

            if ($failedSinceLastCheck) {
                $this->notify(__('Payment failed. Please try again or use a different payment method.'), 'error');
                $this->checkPayment = false;
                $this->lastChecked = null;

                return;
            }
        }

        // Update lastChecked to current time
        $this->lastChecked = now();
        // Back from a gateway, its payment now seen (held, processed, paid) or a way to pay again: the card shows it.
        if ($this->checkPayment && $this->status()->state !== PaymentStatus::CHECKING) {
            $this->checkPayment = false;
            $this->statusMemo = null;
        }

        // Check if invoice is paid
        if ($this->invoice->status === 'paid') {
            $this->notify(__('The invoice has been paid.'), 'success');
            $this->checkPayment = false;
            $this->lastChecked = null;
        }

        // Skip render if still checking
        if ($this->checkPayment) {
            return $this->skipRender();
        }
    }

    public function render()
    {
        return view('invoices.show', ['paymentStatus' => $this->status()])->layoutData([
            'title' => $this->invoice->displayTitle(),
            'sidebar' => true,
        ]);
    }

    public function downloadPDF()
    {
        return response()->streamDownload(function () {
            echo PDF::generateInvoice($this->invoice)->output(); // the PDF only: stream() is a whole response, its headers written into the file
        }, $this->invoice->pdfFileName());
    }
}
