<?php

namespace App\Livewire\Client;

use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\Currency;
use App\Models\Gateway;
use App\Support\CurrentClient;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class PaymentMethods extends Component
{
    use WithPagination;

    #[Url('setup', except: false, nullable: true)]
    public $setupModalVisible = false;

    public $gateway;

    /** Renewals charged to the account's saved method (decision 6; WHMCS «disable automatic card charging» inverted). */
    public bool $autoCharge = true;

    /** The account the page was opened for: another tab switching account does not turn this page on it (code review of C2). */
    #[Locked]
    public ?int $clientId = null;

    public function mount(): void
    {
        $this->clientId = CurrentClient::id();
        $this->autoCharge = (bool) CurrentClient::model()->auto_charge;
    }

    private function sameAccount(): bool
    {
        // The route's `client.can:invoices`, again for Livewire's own requests.
        abort_unless(CurrentClient::allows('invoices'), 403);
        if ((int) CurrentClient::id() === (int) $this->clientId) {
            return true;
        }
        $this->notify(__('You switched account in another window: reload this page.'), 'error');

        return false;
    }

    public function updatedAutoCharge($value): void
    {
        if (!$this->sameAccount()) {
            $this->autoCharge = (bool) CurrentClient::model()->auto_charge;

            return;
        }
        CurrentClient::model()->update(['auto_charge' => (bool) $value]);
        $this->notify(__('account.auto_charge_saved'), 'success');
    }

    /** The method that pays the account's renewals (one per account, decision 6). */
    public function makeDefault($billingAgreementUlid)
    {
        if (!$this->sameAccount()) {
            return;
        }
        $agreement = CurrentClient::model()->billingAgreements()->where('ulid', $billingAgreementUlid)->first();
        if (!$agreement) {
            return $this->notify(__('Billing agreement not found'), 'error');
        }
        CurrentClient::model()->update(['default_billing_agreement_id' => $agreement->id]);
        $this->notify(__('account.default_payment_method_saved'), 'success');
    }

    private $setup = null;

    #[Computed]
    private function gateways()
    {
        $gateways = ExtensionHelper::getBillingAgreementGateways();
        if (count($gateways) > 0 && !$this->gateway) {
            $this->gateway = $gateways[0]->id;
        }

        return $gateways;
    }

    public function updatedSetupModalVisible($value)
    {
        // If we have only one gateway, we can auto-select them
        if ($value && count($this->gateways) === 1) {
            $this->gateway = $this->gateways[0]->id;
            $this->createBillingAgreement();
        }
    }

    public function createBillingAgreement()
    {
        $this->validate([
            'gateway' => 'required',
        ]);

        // Check if gateway is valid and supports the selected currency
        if (!in_array($this->gateway, array_column($this->gateways, 'id'))) {
            $this->addError('gateway', __('account.invalid_payment_gateway'));

            return;
        }

        $this->setup = ExtensionHelper::createBillingAgreement(CurrentClient::model(), Gateway::find($this->gateway));

        // If setup is a string, it's a URL to redirect to
        if (is_string($this->setup)) {
            $this->redirect($this->setup);
        }
    }

    public function removePaymentMethod($billingAgreementUlid)
    {
        $billingAgreement = CurrentClient::model()->billingAgreements()->where('ulid', $billingAgreementUlid)->first();
        if (!$billingAgreement) {
            return $this->notify(__('Billing agreement not found'), 'error');
        }

        // Call the gateway to cancel the billing agreement if supported
        $succeeded = ExtensionHelper::cancelBillingAgreement($billingAgreement);

        if ($succeeded) {
            $this->notify(__('Payment method removed successfully'), 'success');
            $billingAgreement->delete();
        } else {
            $this->notify(__('Failed to remove payment method'), 'error');
        }
    }

    public function cancelSetup()
    {
        $this->setupModalVisible = false;
    }

    public function render()
    {
        return view('client.account.payment-methods', [
            'transactions' => CurrentClient::model()->transactions()->with(['invoice', 'gateway'])->latest()->paginate(config('settings.pagination')),
            'billingAgreements' => CurrentClient::model()->billingAgreements()->latest()->get(),
            'defaultAgreementId' => CurrentClient::model()->fresh()?->default_billing_agreement_id,
        ])->layoutData([
            'sidebar' => true,
            'title' => 'Payment Methods',
        ]);
    }
}
