<?php

namespace App\Livewire\DomainShop;

use App\Livewire\Component;
use App\Services\Domains\Lookup\DomainLookup;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Url;
use Nixpal\DomainTools\DomainName;

/**
 * Public whois (D8a): who has a name, from its registry (RDAP, else port 43 with personal data masked; .gr later, with
 * grepp). Registrar, dates, status, nameservers, DNSSEC: what the registry publishes. At most 10 per 10 minutes per
 * address; answers cached an hour.
 */
class Whois extends Component
{
    public const LIMIT = 10;

    #[Url('domain', except: '')]
    public string $query = '';

    /** @var array<string, mixed>|null */
    public ?array $answer = null;

    public ?string $error = null;

    public ?string $fqdn = null;

    public function mount(): void
    {
        if (trim($this->query) !== '') {
            $this->lookup();
        }
    }

    public function lookup(): void
    {
        $this->answer = null;
        $this->error = null;
        // A full name, any registry: «https://www.example.co.uk/page» → example.co.uk (IDN as punycode).
        $typed = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', mb_strtolower(trim(mb_substr($this->query, 0, 255))));
        $typed = trim(preg_replace('/^www\./', '', (string) preg_replace('#[/?\#:].*$#', '', (string) $typed)), ' .');
        $fqdn = DomainName::toAscii($typed);
        if ($fqdn === null || !DomainName::validDomain($fqdn)) {
            $this->error = __('domains.shop.not_a_full_name');

            return;
        }
        $key = 'domain-whois:' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, self::LIMIT)) {
            $this->error = __('domains.shop.too_many');

            return;
        }
        RateLimiter::hit($key, 600);
        $this->fqdn = $fqdn;
        $result = app(DomainLookup::class)->whois($fqdn);
        $this->answer = [
            'status' => $result['status'],
            'record' => $result['record'] === null ? null : (array) $result['record'],
            'text' => $result['text'],
        ];
    }

    public function render()
    {
        return view('domain-shop.whois')->layoutData(['title' => __('domains.shop.whois_title')]);
    }
}
