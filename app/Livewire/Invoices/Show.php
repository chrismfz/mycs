<?php

namespace App\Livewire\Invoices;

use App\Classes\PDF;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\BillingAgreement;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Support\Facades\Request;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

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
        if ($this->invoice->transactions()->where('status', InvoiceTransactionStatus::Processing)->exists()) {
            $this->checkPayment = true;
        }

        // Load relations
        $this->invoice->load('transactions', 'transactions.gateway', 'transactions.invoice', 'adjustmentNotes');

        if ($this->showPayModal && $this->invoice->status !== Invoice::STATUS_UNPAID) {
            $this->showPayModal = false;
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
        return $this->accountAgreements()->with('gateway')->get();
    }

    /**
     * The invoice's account's saved payment methods, whichever account the client area is on: one account's card
     * never pays, nor is set on the services of, another's invoice (docs/accounts.md, PR 2a).
     *
     * @return \Illuminate\Database\Eloquent\Builder<BillingAgreement>
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
            ->whereHasMorph('reference', [Service::class], function ($query) {
                $query->whereHas('plan', function ($planQuery) {
                    $planQuery->whereNotIn('type', ['one-time', 'free']);
                });
            });
    }

    public function updatedShowPayModal($value)
    {
        if ($value && $this->invoice->status !== Invoice::STATUS_UNPAID) {
            $this->showPayModal = false;
        }
    }

    public function processPayment()
    {
        if ($this->invoice->status !== Invoice::STATUS_UNPAID) {
            return $this->notify(__('This invoice cannot be paid.'), 'error');
        }
        // Left open for staff (it holds gateway money): paying it would buy nothing, so neither credit nor a gateway.
        if ($this->invoice->billsEndedService()) {
            return $this->notify(__('This invoice bills a service that has ended: please contact us.'), 'error');
        }

        if (is_null($this->selectedMethod)) {
            return;
        }

        if ($this->selectedMethod === 'credit') {
            if (!config('settings.credits_enabled')) {
                return $this->notify(__('Paying with credits is currently disabled.'), 'error');
            }
            if ($this->invoice->items->contains(fn ($item) => $item->reference_type === Credit::class)) {
                return $this->notify(__('This invoice cannot be paid with credits.'), 'error');
            }

            return $this->payWithCredit();
        }

        if (str_starts_with($this->selectedMethod, 'gateway-')) {
            $gatewayId = substr($this->selectedMethod, 8);

            return $this->payWithMethod($gatewayId);
        }

        if ($this->setAsDefault) {
            $invoiceItems = $this->recurringServices()->get();
            $agreement = $this->accountAgreements()->where('ulid', $this->selectedMethod)->first();
            if (!$agreement) {
                return $this->notify(__('Invalid payment method.'), 'error');
            }

            foreach ($invoiceItems as $invoiceItem) {
                $service = $invoiceItem->reference;
                $service->update(['billing_agreement_id' => $agreement->id]);
            }

            if ($invoiceItems->count() > 0) {
                $this->notify(__('Default payment method has been updated for recurring services.'), 'success');
            }
        }

        return $this->payWithSavedMethod($this->selectedMethod);
    }

    private function payWithMethod($methodId)
    {
        if (!in_array($methodId, array_column($this->gateways, 'id'))) {
            return $this->notify(__('Invalid payment method.'), 'error');
        }

        $this->pay = ExtensionHelper::pay(Gateway::where('id', $methodId)->first(), $this->invoice);

        if (is_string($this->pay)) {
            $this->redirect($this->pay);
        }
    }

    private function payWithCredit()
    {
        // Read and spend the credit under the payment locks (RecordPaymentService): never a stale copy.
        $transaction = (new RecordPaymentService)->payWithCredit($this->invoice);
        $this->invoice = $this->invoice->fresh();
        if ($transaction === null) {
            return;
        }
        if ($this->invoice->status === Invoice::STATUS_PAID) {
            return $this->redirect(route('invoices.show', $this->invoice), true);
        }
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

        $success = ExtensionHelper::charge($agreement->gateway, $this->invoice, $agreement);

        if ($success === true) {
            $this->notify(__('Successfully charged the saved payment method.'), 'success');

            return $this->redirect(route('invoices.show', $this->invoice) . '?checkPayment', true);
        } else {
            return $this->notify(__('Could not process payment. Please try again or use a different payment method.'), 'error');
        }
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
        return view('invoices.show')->layoutData([
            'title' => __('invoices.invoice', ['id' => $this->invoice->number]),
            'sidebar' => true,
        ]);
    }

    public function downloadPDF()
    {
        return response()->streamDownload(function () {
            echo PDF::generateInvoice($this->invoice)->stream();
        }, 'invoice-' . ($this->invoice->number ?? $this->invoice->id) . '.pdf');
    }
}
