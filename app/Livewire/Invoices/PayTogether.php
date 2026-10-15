<?php

namespace App\Livewire\Invoices;

use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\BillingAgreement;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGroup;
use App\Services\Invoice\MassPay;
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

        return ExtensionHelper::getCheckoutGateways((float) $this->paymentGroup->total, $this->paymentGroup->currency_code, 'invoice', $items);
    }

    #[Computed]
    public function savedPaymentMethods()
    {
        $usable = array_column(ExtensionHelper::getBillingAgreementGateways(), 'id');

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
            if (!in_array($gatewayId, array_map('strval', array_column($this->gateways, 'id')), true)) {
                return $this->notify(__('Invalid payment method.'), 'error');
            }
            $this->pay = ExtensionHelper::payGroup(Gateway::query()->findOrFail($gatewayId), $this->paymentGroup);
            if (is_string($this->pay)) {
                $this->redirect($this->pay);
            }

            return;
        }

        $agreement = $this->savedPaymentMethods->firstWhere('ulid', $this->selectedMethod);
        if ($agreement === null || $agreement->gateway === null) {
            return $this->notify(__('Invalid payment method.'), 'error');
        }
        if (ExtensionHelper::chargeGroup($agreement->gateway, $this->paymentGroup, $agreement) === true) {
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
