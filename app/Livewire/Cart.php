<?php

namespace App\Livewire;

use App\Classes\Cart as ClassesCart;
use App\Classes\Price;
use App\Exceptions\CouponUnusableException;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Cart as CartModel;
use App\Models\Client;
use App\Models\Contact;
use App\Models\DomainTld;
use App\Services\Domains\Ordering\DomainOffer;
use App\Services\Domains\Ordering\DomainOrderLine;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\Order\StaffOrderNotice;
use App\Support\CurrentClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Throwable;

class Cart extends Component
{
    #[Locked]
    public $total;

    public $gateway;

    public $coupon;

    public $use_credits = true;

    public $tos;

    /** The order's notes (WHMCS «Order Notes»): for staff, on the order. */
    public $notes = '';

    /** Who owns the domains ordered (D8b, owner 2026-10-17): the account's primary contact unless another is chosen. */
    public ?int $registrant = null;

    /** @var array<int, string> a transfer's EPP code by cart item, as typed (saved on checkout and when it changes) */
    public array $epp = [];

    public function mount()
    {
        $this->registrant = Auth::check() ? CurrentClient::model()?->primaryContact?->id : null;
        if (ClassesCart::get()->coupon_id) {
            $this->coupon = ClassesCart::get()->coupon;
        }
        $this->updateTotal();
    }

    private function updateTotal()
    {
        if (ClassesCart::items()->count() == 0) {
            $this->total = null;

            return;
        }
        // The items' invoice lines (Δ1): what checkout charges.
        $this->total = Price::ofTotal(ClassesCart::items()->sum(fn ($item) => $item->lineTotal()), ClassesCart::items()->sum(fn ($item) => $item->lineTax()), ClassesCart::get()->currency);
        $this->gateways = ExtensionHelper::getCheckoutGateways($this->total->total, $this->total->currency->code, 'cart', ClassesCart::items());
        if (count($this->gateways) > 0 && !array_search($this->gateway, array_column($this->gateways, 'id')) !== false) {
            $this->gateway = $this->gateways[0]->id;
        }
    }

    public function applyCoupon()
    {
        if ($this->coupon && ClassesCart::get()->coupon_id) {
            return $this->notify(__('Coupon code already applied'), 'error');
        }

        try {
            $cart = ClassesCart::applyCoupon($this->coupon);
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');
            $this->coupon = null;

            return;
        }
        $this->coupon = $cart->coupon;
        $this->updateTotal();
        $this->notify(__('Coupon code applied successfully'), 'success');
    }

    public function removeCoupon()
    {
        if (!$this->coupon || !ClassesCart::get()->coupon_id) {
            return $this->notify(__('No coupon code applied'), 'error');
        }
        ClassesCart::removeCoupon();
        $this->coupon = null;
        $this->updateTotal();
        $this->notify(__('Coupon code removed successfully'), 'success');
    }

    public function removeProduct($index)
    {
        ClassesCart::remove($index);
        $this->updateTotal();
    }

    public function updateQuantity($index, $quantity)
    {
        ClassesCart::updateQuantity($index, $quantity);
        $this->updateTotal();
    }

    /** A domain's years (D8b): one of those its TLD prices for the operation. */
    public function updateDomainYears(int $id, int $years): void
    {
        try {
            ClassesCart::updateDomain($id, $years, null);
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');
        }
        $this->updateTotal();
    }

    /** A transfer's EPP code, kept on its cart item (encrypted) as typed. */
    public function updatedEpp($value, $id): void
    {
        // One code for one item, or the whole map at once (adversarial ADV-5: never a 500).
        $codes = $id === null ? (is_array($value) ? $value : []) : [$id => $value];
        foreach ($codes as $item => $code) {
            if (is_string($code) || is_int($code)) {
                ClassesCart::updateDomain((int) $item, null, (string) $code);
            }
        }
    }

    /** @return Collection<int, Contact> the account's contacts a domain may belong to */
    public function registrantOptions(): Collection
    {
        return Auth::check() ? (CurrentClient::model()?->contacts()->active()->orderBy('id')->get() ?? collect()) : collect();
    }

    // Checkout
    public function checkout()
    {
        if (ClassesCart::items()->count() === 0) {
            return $this->notify(__('Your cart is empty'), 'error');
        }
        if (!Auth::check()) {
            return redirect()->guest('login');
        }
        if (config('settings.mail_must_verify') && !Auth::user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }
        if (config('settings.tos') && !$this->tos) {
            return $this->notify(__('You must accept the terms of service'), 'error');
        }
        $this->validate(['notes' => 'nullable|string|max:2000'], attributes: ['notes' => __('Notes')]);
        // Ordering for the current account needs `orders` (docs/accounts.md, PR 2b). A login without any account gets
        // its own below, which it owns.
        if (CurrentClient::model() !== null && !CurrentClient::allows('orders')) {
            return $this->notify(__('You do not have access to this area of the account.'), 'error');
        }

        try {
            // Each domain free (to register) or taken (to transfer), asked of its registrar before the account is locked
            // (code review M2 of #179); the rest is checked again under the lock.
            foreach (ClassesCart::items()->filter(fn ($item) => $item->isDomain()) as $item) {
                app(DomainOffer::class)->check($item->domain['fqdn'], $item->domain['operation'],
                    (int) $item->domain['years'], (string) ClassesCart::get()->currency_code);
            }
            // The order is the current account's; a login without any account gets its own here (WHMCS opens the client
            // account at checkout), kept even when the order is refused: its owner fills in the country (AUD5-1) there.
            $opened = CurrentClient::model() ?? Client::openFor(Auth::user());
            $placed = DB::transaction(function () use ($opened) {
                // Its row first, as every payment path locks it (no lock-order deadlock).
                $client = $opened;
                Client::lock($client->id);

                // The cart read again under its lock (CART-6): a second checkout of it (another tab) finds it gone.
                $cart = CartModel::query()->whereKey(ClassesCart::get()->id)->lockForUpdate()->first();
                $cart?->load('coupon', 'items.plan.prices', 'items.product.configOptions.children.plans.prices');
                if ($cart === null || $cart->items->isEmpty()) {
                    throw new DisplayException(__('Your cart is empty'));
                }

                $lines = $cart->items->reject(fn ($item) => $item->isDomain())->map(fn ($item) => new OrderLine(
                    $item->product, $item->plan, (int) $item->quantity, $item->config_options ?? [], $item->checkout_config ?? [],
                ))->values()->all();
                // Domains (D8b): owned by the contact chosen, one of the account's, with its details complete.
                $domainItems = $cart->items->filter(fn ($item) => $item->isDomain());
                $domains = [];
                if ($domainItems->isNotEmpty()) {
                    $registrant = $this->registrant === null ? $client->ensurePrimaryContact()
                        : $client->contacts()->active()->whereKey($this->registrant)->first();
                    if ($registrant === null) {
                        throw new DisplayException(__('domains.order.contact_incomplete'));
                    }
                    foreach ($domainItems as $item) {
                        $tld = DomainTld::query()->with('prices')->find($item->domain['tld_id']);
                        if ($tld === null) {
                            throw new DisplayException(__('domains.order.not_offered'));
                        }
                        $domains[] = new DomainOrderLine($item->domain['fqdn'], $tld, $item->domain['operation'], (int) $item->domain['years'],
                            $registrant, $this->epp[$item->id] ?? $item->epp_code);
                    }
                }
                // Priced and invoiced here, from the lines as they are now (CART-7), not from the total on the page.
                $placed = (new PlaceOrderService)->place($client, $cart->currency_code, $lines, $cart->coupon, notes: $this->notes, domains: $domains, ip: request()->ip());
                $cart->delete();

                return $placed;
            });
        } catch (CouponUnusableException $e) {
            // Removed after the rollback, or the rollback would put it back (CART-19).
            ClassesCart::removeCoupon();
            $this->coupon = null;
            $this->updateTotal();

            return $this->notify($e->getMessage(), 'error');
        } catch (DisplayException $e) {
            return $this->notify($e->getMessage(), 'error');
        } catch (Throwable $e) {
            report($e);

            return $this->notify(__('An error occurred while processing your order. Please try again later.'), 'error');
        }

        // The cart row is gone; this drops its cookie.
        ClassesCart::clear();

        // Staff are told (WHMCS «New Order Notification»), after the commit. A mail that fails never fails the order.
        try {
            StaffOrderNotice::send($placed->order);
        } catch (Throwable $e) {
            report($e);
        }

        if ($placed->invoice === null && $placed->order->services()->doesntExist()) {
            return $this->redirect(route('domains'), true); // only domains, nothing to pay (a free transfer)
        }
        if ($placed->invoice === null) {
            // Nothing to pay: one service goes to its page, more to the list.
            if ($placed->order->services()->count() === 1) {
                return $this->redirect(route('services.show', $placed->order->services()->first()), true);
            }

            return $this->redirect(route('services'), true);
        }

        return $this->redirect(route('invoices.show', [$placed->invoice, 'pay' => true]), true);
    }

    public function render()
    {
        return view('cart');
    }
}
