<?php

namespace App\Livewire\DomainShop;

use App\Classes\Cart;
use App\Exceptions\DisplayException;
use App\Livewire\Component;
use App\Models\CartItem;
use App\Models\Currency;
use App\Models\DomainTld;
use App\Services\Domains\DomainSearch;
use App\Services\Domains\Lookup\PublicLookups;
use App\Services\Domains\Lookup\VisitorKey;
use App\Services\Domains\Ordering\DomainOffer;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * A domain transfer, its own page in the menu (owner 2026-10-20: «η μεταφορά μέσα στην αναζήτηση δεν θα τη βρει ο
 * κόσμος»): one name with its EPP code, whether it can come to us and at what price, «Add to cart» staying here so more
 * can follow; or many at once, a line each («domain code»). The same checks and limits as the search and the cart.
 */
class Transfer extends Component
{
    #[Url('domain', except: '')]
    public string $query = '';

    /** @var array<string, mixed>|null the name checked: its status, whether we transfer it, at what price */
    #[Locked]
    public ?array $result = null;

    #[Locked]
    public ?string $error = null;

    /** «one» or «bulk» (owner 2026-10-20: several domains in one order). */
    #[Url('mode', except: 'one')]
    public string $mode = 'one';

    /** The EPP code typed with the name (also asked in the cart). */
    public string $epp = '';

    /** Bulk: a line each, «domain code» (code optional; space, tab, comma or semicolon between). */
    public string $lines = '';

    /** @var array<string, true|string> bulk: each line's domain => added, or why not */
    #[Locked]
    public array $bulk = [];

    /** @var list<string> what this page put in the cart: a new cart's cookie reaches only the next request */
    #[Locked]
    public array $added = [];

    /** At most this many lines at once (the cart holds 15). */
    public const BULK_MAX = 15;

    public function mount(): void
    {
        // No transfers sold: no page for them (the menu has no link either).
        abort_unless(DomainOffer::allowed('transfer'), 404);
        if (trim($this->query) !== '') {
            $this->check();
        }
    }

    public function check(): void
    {
        $this->result = null;
        $this->error = null;
        $this->query = mb_substr(trim($this->query), 0, 100);
        if ($this->query === '') {
            return;
        }
        // A transfer is of a whole name: «mybusiness» alone says nothing of which one.
        $typed = mb_strtolower((string) preg_replace('#^[a-z][a-z0-9+.-]*://(www\.)?#i', '', $this->query));
        $typed = (string) preg_replace('#[/?\#:].*$#', '', $typed);
        if (!str_contains($typed, '.')) {
            $this->error = __('domains.shop.not_a_full_name');

            return;
        }
        $key = 'domain-search-hits:' . VisitorKey::of(request());
        if (RateLimiter::tooManyAttempts($key, Search::LIMIT)) {
            $this->error = __('domains.shop.too_many');

            return;
        }
        RateLimiter::hit($key, 600);
        if (!PublicLookups::take(DomainSearch::ALTERNATIVES + 1)) {
            $this->error = __('domains.shop.busy');

            return;
        }
        $currency = Search::currency();
        $results = app(DomainSearch::class)->search($this->query, $currency);
        if ($results === null) {
            $this->error = __('domains.shop.not_a_name');

            return;
        }
        $row = $results[0] ?? null;
        if ($row === null) {
            $this->error = __('domains.shop.not_in_currency');

            return;
        }
        if (!str_ends_with($typed, '.' . $row['tld'])) {
            $this->error = __('domains.transfer.unsold', ['tld' => '.' . substr($typed, (int) strpos($typed, '.') + 1)]);

            return;
        }
        $tld = DomainTld::query()->with('prices')->where('tld', $row['tld'])->first();
        $years = $tld?->shortestYears('transfer', $currency);
        $this->result = $row + [
            'transfer_years' => $years,
            'transfer_price' => $tld !== null && $years !== null ? $tld->priceFor('transfer', $years, $currency) : null,
        ];
    }

    /** «Add to cart»: stays here, so another can follow (every rule checked again: DomainOffer). */
    public function transfer(): void
    {
        $this->toCart('transfer');
    }

    /** Not registered after all: register it instead. */
    public function register(): void
    {
        $this->toCart('register');
    }

    private function toCart(string $operation): void
    {
        $fqdn = (string) ($this->result['fqdn'] ?? '');
        if ($fqdn === '') {
            return;
        }
        try {
            $id = Cart::addDomain(mb_substr($fqdn, 0, 255), $operation);
            // On the item itself: Cart::updateDomain() looks in the cart this request read, empty for a new one.
            if ($operation === 'transfer' && trim($this->epp) !== '' && ($item = CartItem::query()->find($id)) !== null) {
                $item->epp_code = mb_substr(trim($this->epp), 0, 255);
                $item->save();
            }
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        $this->added[] = $fqdn;
        $this->notify(__('domains.transfer.added', ['domain' => $fqdn]));
        $this->dispatch('cartUpdated');
        $this->reset(['query', 'epp', 'result', 'error']);
    }

    /** Bulk: each line's domain and code, added together; what was not, said line by line. */
    public function addMany(): void
    {
        $this->bulk = [];
        $this->error = null;
        $names = [];
        $typed = [];
        foreach (preg_split('/\R/', mb_substr($this->lines, 0, 5000)) ?: [] as $line) {
            $parts = preg_split('/[\s,;]+/', trim($line), 2) ?: [];
            $name = mb_substr(mb_strtolower(trim((string) ($parts[0] ?? ''))), 0, 255);
            if ($name !== '') {
                $names[$name] = isset($parts[1]) ? trim($parts[1]) : null;
                $typed[$name] = trim($line);
            }
        }
        if ($names === []) {
            return;
        }
        if (count($names) > self::BULK_MAX) {
            $this->error = __('domains.transfer.too_many_lines', ['count' => self::BULK_MAX]);

            return;
        }
        // Each name a search of the visitor's (review of #271): the bulk is no way around the search's limit.
        $key = 'domain-search-hits:' . VisitorKey::of(request());
        if (RateLimiter::remaining($key, Search::LIMIT) < count($names)) {
            $this->error = __('domains.shop.too_many');

            return;
        }
        if (!PublicLookups::take(count($names))) {
            $this->error = __('domains.shop.busy');

            return;
        }
        foreach ($names as $ignored) {
            RateLimiter::hit($key, 600);
        }
        try {
            $this->bulk = Cart::addDomains($names, 'transfer');
        } catch (DisplayException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->added = array_merge($this->added, array_keys(array_filter($this->bulk, fn ($done) => $done === true)));
        $this->dispatch('cartUpdated');
        // The lines added leave the box; those refused stay, to be corrected.
        $this->lines = implode("\n", array_map(fn ($name) => $typed[$name] ?? $name, array_keys(array_filter($this->bulk, fn ($done) => $done !== true))));
    }

    public function render()
    {
        $inCart = Cart::items()->filter(fn ($item) => $item->isDomain() && $item->domain['operation'] === 'transfer')->toBase()->map(fn ($item) => $item->domain['fqdn'])
            ->merge($this->added)->unique()->values();

        return view('domain-shop.transfer', ['currency' => Currency::query()->find(Search::currency()), 'inCart' => $inCart])
            ->layoutData(['title' => __('domains.transfer.title')]);
    }
}
