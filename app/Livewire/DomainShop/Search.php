<?php

namespace App\Livewire\DomainShop;

use App\Livewire\Component;
use App\Models\Currency;
use App\Services\Domains\DomainSearch;
use Illuminate\Support\Facades\RateLimiter;
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
    public ?array $results = null;

    public ?string $error = null;

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
        $this->query = mb_substr(trim($this->query), 0, 100);
        if ($this->query === '') {
            return;
        }
        $key = 'domain-search:' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, self::LIMIT)) {
            $this->error = __('domains.shop.too_many');

            return;
        }
        RateLimiter::hit($key, 600);
        $this->results = app(DomainSearch::class)->search($this->query, self::currency());
        if ($this->results === null) {
            $this->error = __('domains.shop.not_a_name');
        } elseif ($this->results === []) {
            $this->results = null;
            $this->error = __('domains.shop.not_in_currency');
        }
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
