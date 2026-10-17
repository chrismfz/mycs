<?php

namespace App\Livewire\DomainShop;

use App\Classes\Cart;
use App\Exceptions\DisplayException;
use App\Livewire\Component;
use App\Models\Currency;
use App\Services\Domains\DomainSearch;
use App\Services\Domains\Lookup\VisitorKey;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * The shop's domain search (D8a, owner 2026-10-17): one box («find your domain»), the exact name first, then the TLDs
 * staff put first, each free or taken with its yearly price (VAT as the catalog shows it) and «then X a year» when the
 * renewal differs; a taken one links to its whois. Public; at most 30 searches per 10 minutes per address. Ordering
 * comes with D8b.
 */
class Search extends Component
{
    public const LIMIT = 30;

    #[Url('domain', except: '')]
    public string $query = '';

    /** @var list<array<string, mixed>>|null */
    #[Locked]
    public ?array $results = null;

    #[Locked]
    public ?string $error = null;

    /** A TLD typed that we do not sell (review R13): said, not silently replaced. */
    #[Locked]
    public ?string $unsold = null;

    public function mount(): void
    {
        if (trim($this->query) !== '') {
            $this->search();
        }
    }

    public function search(): void
    {
        $this->results = null;
        $this->error = null;
        $this->unsold = null;
        $this->query = mb_substr(trim($this->query), 0, 100);
        if ($this->query === '') {
            return;
        }
        // The caller's own address (review R2: not one an X-Forwarded-For header names, App\Support\CallerIp).
        $key = 'domain-search-hits:' . VisitorKey::of(request());
        if (RateLimiter::tooManyAttempts($key, self::LIMIT)) {
            $this->error = __('domains.shop.too_many');

            return;
        }
        RateLimiter::hit($key, 600);
        $search = app(DomainSearch::class);
        $this->results = $search->search($this->query, self::currency());
        if ($this->results) {
            $typed = mb_strtolower((string) preg_replace('#^[a-z][a-z0-9+.-]*://(www\.)?#i', '', $this->query));
            $typed = (string) preg_replace('#[/?\#:].*$#', '', $typed);
            if (str_contains($typed, '.') && !str_ends_with($typed, '.' . $this->results[0]['tld'])) {
                $this->unsold = '.' . substr($typed, (int) strpos($typed, '.') + 1);
            }
        }
        if ($this->results === null) {
            $this->error = __('domains.shop.not_a_name');
        } elseif ($this->results === []) {
            $this->results = null;
            $this->error = __('domains.shop.not_in_currency');
        }
    }

    /** «Add»: a free name to register, to the cart (D8b), checked again there (DomainOffer). */
    public function add(string $fqdn)
    {
        return $this->toCart($fqdn, 'register');
    }

    /** «Transfer it to us»: a taken name to transfer (its EPP code asked in the cart). */
    public function transfer(string $fqdn)
    {
        return $this->toCart($fqdn, 'transfer');
    }

    private function toCart(string $fqdn, string $operation)
    {
        try {
            Cart::addDomain(mb_substr($fqdn, 0, 255), $operation);
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');

            return null;
        }

        return $this->redirectRoute('cart', navigate: true);
    }

    public static function currency(): string
    {
        return (string) session('currency', config('settings.default_currency'));
    }

    public function render()
    {
        return view('domain-shop.search', ['currency' => Currency::query()->find(self::currency())])
            ->layoutData(['title' => __('domains.shop.title')]);
    }
}
