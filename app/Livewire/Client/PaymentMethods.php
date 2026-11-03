<?php

namespace App\Livewire\Client;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Service;
use App\Services\Billing\RenewalBilling;
use App\Services\Payment\ItemPaymentMethod;
use App\Support\Actor;
use App\Support\CurrentClient;
use App\Support\ItemPaymentAccess;
use App\Support\PayOptions;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/** @property-read array<int, Gateway> $gateways the gateways that save methods (the computed gateways()) */
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

    /** The method marked for the account's automatic payments (its ulid), as the cards' radios show it (PR 6a). */
    public ?string $automaticMethod = null;

    /**
     * «Paid with» of each service and domain in the table (PR 6b), by row key (`s{id}`, `d{id}`): a gateway's id, or ''
     * for the account's. Set from the database on every render, so a refused change shows what it still is.
     *
     * @var array<mixed>
     */
    public array $ways = [];

    public function mount(): void
    {
        $this->clientId = CurrentClient::id();
        $this->autoCharge = (bool) CurrentClient::model()->auto_charge;
        $this->automaticMethod = $this->markedMethod();
    }

    /** The ulid of the method the page's account pays its renewals with, if any. */
    private function markedMethod(): ?string
    {
        $id = Client::query()->whereKey($this->clientId)->value('default_billing_agreement_id');

        return $id === null ? null : BillingAgreement::query()->where('client_id', $this->clientId)->whereKey($id)->value('ulid');
    }

    /** A radio picked (PR 6a): makeDefault and its guards, then the radios show what the account has, refused or not. */
    public function updatedAutomaticMethod($value): void
    {
        if (is_string($value) && $value !== '') {
            $this->makeDefault($value);
        }
        $this->automaticMethod = $this->markedMethod();
    }

    /** Staff in «Login as client» change how the customer's money is spent (a saved card, the vault, auto-charge) only with
     * the admin permission the same change needs there (security audit SA-5). */
    private function staffMayPay(): bool
    {
        if (Actor::impersonating() && !Actor::staffMayAs('pay')) {
            $this->notify(__('account.notifications.not_while_impersonating_money'), 'error');

            return false;
        }

        return true;
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
        if (!$this->staffMayPay()) {
            $this->autoCharge = (bool) CurrentClient::model()->auto_charge;

            return;
        }
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
        if (!$this->staffMayPay() || !$this->sameAccount()) {
            return;
        }
        $agreement = CurrentClient::model()->billingAgreements()->where('ulid', $billingAgreementUlid)->first();
        if (!$agreement) {
            return $this->notify(__('Billing agreement not found'), 'error');
        }
        // One of a gateway staff disabled would never be charged (ADV-540-3).
        if (!ExtensionHelper::gatewayEnabled($agreement->gateway_id)) {
            return $this->notify(__('This payment method cannot be used now.'), 'error');
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
        // As the page's other actions (payment sweep): a method is saved on the account the page was opened for.
        if (!$this->staffMayPay() || !$this->sameAccount()) {
            return;
        }
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

    /** «Add a payment method» in a provider's card (PR 6a): that gateway's setup, as the window's button starts it. */
    public function addMethod($gatewayId)
    {
        if (!is_numeric($gatewayId) || !in_array((int) $gatewayId, array_map(fn (Gateway $gateway) => (int) $gateway->id, $this->gateways), true)) {
            return $this->notify(__('account.invalid_payment_gateway'), 'error');
        }
        $this->gateway = (int) $gatewayId;
        $this->createBillingAgreement();
    }

    /**
     * A row's «Paid with» changed (PR 6b): the same change as on its own page (ItemPaymentMethod::set, its open invoices
     * following), by whom may make it there (ItemPaymentAccess), on the page's account only, and the money permission's
     * in «Login as client» (SA-5).
     */
    public function updatedWays($value, $key): void
    {
        if (!is_string($key) || !$this->sameAccount()) {
            return;
        }
        $item = $this->item($key);
        if ($item === null || !ItemPaymentAccess::offersChoice($item)) {
            $this->notify(__('services.payment_method_not_available'), 'error');

            return;
        }
        abort_unless(ItemPaymentAccess::mayChange($item), 403);
        if (Actor::impersonating() && !Actor::staffMayAs('pay')) {
            $this->notify(__('account.notifications.not_while_impersonating_money'), 'error');

            return;
        }
        $gatewayId = match (true) {
            $value === null || $value === '' || $value === '0' || $value === 0 => null,
            is_int($value) && $value > 0, is_string($value) && ctype_digit($value) => (int) $value,
            default => false,
        };
        if ($gatewayId === false) {
            $this->notify(__('services.payment_method_not_available'), 'error');

            return;
        }
        try {
            ItemPaymentMethod::set($item, $gatewayId);
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        $this->notify(__('services.payment_method_saved'), 'success');
    }

    /** A row's service or domain, of the page's account only. */
    private function item(string $key): Service|Domain|null
    {
        if (preg_match('/^([sd])([1-9][0-9]{0,18})\z/', $key, $match) !== 1) {
            return null;
        }
        $query = $match[1] === 's' ? Service::query() : Domain::query();

        return $query->where('client_id', $this->clientId)->whereKey((int) $match[2])->first();
    }

    /**
     * The table's rows (PR 6b): the account's services and domains that have a payment method to choose
     * (ItemPaymentAccess::offersChoice), services first, a page at a time.
     *
     * @return LengthAwarePaginator<int, Service|Domain>
     */
    private function items(Client $client): LengthAwarePaginator
    {
        // Only what the login may see (`products`, `domains`): `invoices` opens this page, not those (review of #612).
        $services = Service::query()->where('client_id', $client->id)->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
            ->whereNull('subscription_id')->whereHas('plan', fn ($plan) => $plan->where('type', 'recurring'))
            ->when(!CurrentClient::allows('products'), fn ($query) => $query->whereRaw('1 = 0'));
        $domains = Domain::query()->where('client_id', $client->id)
            ->whereIn('status', array_map(fn ($status) => $status->value, RenewalBilling::BILLED_DOMAINS))
            ->when(!CurrentClient::allows('domains'), fn ($query) => $query->whereRaw('1 = 0'));
        $perPage = max(1, (int) config('settings.pagination') ?: 15); // 0 or none: paginate()'s 15, as the transactions (ADV-612-2)
        // A crafted page (gotoPage('abc')) is the first, not a 500: paginate() would check it, this is built by hand (review of #612).
        $page = filter_var($this->getPage('items'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) ?: 1;
        $serviceCount = (clone $services)->count();
        $total = $serviceCount + (clone $domains)->count();
        $skip = ($page - 1) * $perPage;
        $rows = $services->with(['plan', 'product', 'client', 'paymentGateway'])->orderBy('id')->skip($skip)->take($perPage)->get();
        if ($rows->count() < $perPage) {
            $rows = $rows->concat($domains->with(['client', 'paymentGateway'])->orderBy('id')
                ->skip(max(0, $skip - $serviceCount))->take($perPage - $rows->count())->get());
        }

        return new LengthAwarePaginator($rows->values(), $total, $perPage, $page, ['path' => request()->url(), 'pageName' => 'items']);
    }

    public function removePaymentMethod($billingAgreementUlid)
    {
        if (!$this->staffMayPay() || !$this->sameAccount()) {
            return;
        }
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

    /**
     * The account's providers, one card each (docs/pay-flow-ux.md, PR 6a), in the invoice's order (PayOptions::byProvider):
     * those with saved methods (the default's first), then the ones that save methods, then those that save none (paid
     * from the invoice). A method whose gateway was deleted is under «Other», to be removed.
     *
     * @param  Collection<int, BillingAgreement>  $agreements  with their gateways
     * @return list<array{gateway: Gateway|null, saved: list<BillingAgreement>, canAdd: bool}>
     */
    private function providers(Collection $agreements, ?int $defaultAgreementId): array
    {
        $savable = array_map(fn (Gateway $gateway) => (int) $gateway->id, $this->gateways);
        $gateways = ExtensionHelper::customerGateways()->sortBy(fn (Gateway $gateway) => in_array((int) $gateway->id, $savable, true) ? 0 : 1)->values();
        $cards = array_map(fn (array $group) => ['gateway' => $group['gateway'], 'saved' => $group['saved'],
            'canAdd' => in_array((int) $group['gateway']->id, $savable, true)], PayOptions::byProvider($agreements, $gateways, $defaultAgreementId));
        $orphans = $agreements->filter(fn (BillingAgreement $agreement) => $agreement->gateway === null)->values()->all();
        if ($orphans !== []) {
            $cards[] = ['gateway' => null, 'saved' => $orphans, 'canAdd' => false];
        }

        return $cards;
    }

    public function render()
    {
        // The radios say what the account has on every render, not only after their own change: the default may have
        // changed meanwhile (an invoice paid with «save» in another tab, staff; review of 6a).
        $this->automaticMethod = $this->markedMethod();
        $client = CurrentClient::model()->fresh();
        $agreements = CurrentClient::model()->billingAgreements()->with('gateway')->latest()->get();
        $defaultAgreementId = $client?->default_billing_agreement_id === null ? null : (int) $client->default_billing_agreement_id;
        $items = $client === null ? null : $this->items($client);
        // As the database has it (review of 6a, the radios): a refused or superseded change shows what it still is.
        $this->ways = $items === null ? [] : $items->getCollection()->mapWithKeys(fn (Service|Domain $item) => [self::rowKey($item) => (string) ($item->payment_gateway_id ?? '')])->all();
        $offered = []; // ItemPaymentMethod::options, once per amount and currency
        // A way's label depends on the gateway and the item's own switch only (the account is the page's): once each (review of #612).
        $labels = [];
        $label = function (Service|Domain $item, ?Gateway $gateway) use (&$labels): string {
            return $labels[($gateway?->id ?? 0) . '|' . (int) $item->no_auto_charge . '|' . (int) ($gateway?->enabled ?? 1)] ??= ItemPaymentMethod::optionLabel($item, $gateway);
        };

        return view('client.account.payment-methods', [
            'items' => $items,
            'itemRows' => $items === null ? [] : $items->getCollection()->map(function (Service|Domain $item) use (&$offered, $label) {
                $mayChange = ItemPaymentAccess::mayChange($item);
                $amount = $item instanceof Service ? (string) $item->price : (string) $item->recurring_amount;
                $options = $mayChange ? ($offered[$amount . '|' . $item->currency_code] ??= ItemPaymentMethod::options($item)) : collect();
                // The choices as its page lists them: the account's, those on offer, and its own kept when no longer offered.
                $choices = ['' => $label($item, null)];
                foreach ($options as $gateway) {
                    $choices[(string) $gateway->id] = $label($item, $gateway);
                }
                if ($item->paymentGateway !== null && !isset($choices[(string) $item->payment_gateway_id])) {
                    $choices[(string) $item->payment_gateway_id] = $label($item, $item->paymentGateway);
                }
                // A domain that does not renew (auto renew off, or past its date) says when it expires, not «Renews» (review of #612).
                $renews = $item instanceof Service || ($item->auto_renew && $item->status === DomainStatus::Active);

                return ['key' => self::rowKey($item), 'item' => $item, 'mayChange' => $mayChange, 'choices' => $choices,
                    'current' => $label($item, $item->paymentGateway), 'renews' => $renews,
                    'status' => ItemPaymentMethod::autoChargeStatus($item)];
            })->all(),
            'transactions' => CurrentClient::model()->transactions()->with(['invoice', 'gateway'])->latest()->paginate(config('settings.pagination')),
            'billingAgreements' => $agreements,
            'providers' => $this->providers($agreements, $defaultAgreementId),
            'defaultAgreementId' => $defaultAgreementId,
            'ownWayMethods' => $client === null ? [] : ItemPaymentMethod::ownWayMethods($client),
        ])->layoutData([
            'sidebar' => true,
            'title' => __('account.payment_methods'),
        ]);
    }

    private static function rowKey(Service|Domain $item): string
    {
        return ($item instanceof Service ? 's' : 'd') . $item->id;
    }
}
