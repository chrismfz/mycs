<?php

namespace App\Livewire\Invoices;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGroup;
use App\Services\Invoice\MassPay;
use App\Services\Payment\SavedMethodCharge;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Mass Pay (PR F2): the invoices the customer chose, paid at once through a gateway or a saved payment method. The
 * gateway is opened on the invoice due first for the whole amount and returns there; the payment pays the others as
 * it lands (MassPay::spread). Account credit is not offered here: each invoice takes it on its own page.
 */
class PayTogether extends Component
{
    #[Locked]
    public PaymentGroup $paymentGroup;

    public $selectedMethod = null;

    private $pay = null;

    public function mount()
    {
        $this->paymentGroup->load('invoices', 'invoice');
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

    public function processPayment()
    {
        // Read again as the gateway opens: staff may have changed an invoice, or the customer chosen again elsewhere.
        $this->paymentGroup->refresh();
        if (($stale = MassPay::staleness($this->paymentGroup)) !== null) {
            return $this->notify($stale, 'error');
        }
        if (is_null($this->selectedMethod)) {
            return;
        }

        if (str_starts_with($this->selectedMethod, 'gateway-')) {
            $gatewayId = substr($this->selectedMethod, 8);
            if (!in_array($gatewayId, array_map('strval', array_column($this->gateways(), 'id')), true)) {
                return $this->notify(__('Invalid payment method.'), 'error');
            }
            $this->pay = ExtensionHelper::payGroup(Gateway::query()->findOrFail($gatewayId), $this->paymentGroup);
            if (is_string($this->pay)) {
                $this->redirect($this->pay);
            }

            return;
        }

        $agreement = $this->savedPaymentMethods()->firstWhere('ulid', $this->selectedMethod);
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
