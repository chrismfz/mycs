<?php

namespace App\Livewire\DomainShop;

use App\Livewire\Component;
use App\Models\DomainTld;
use App\Services\Domains\Lookup\DomainLookup;
use App\Services\Domains\Lookup\PublicLookups;
use App\Services\Domains\Lookup\VisitorKey;
use App\Traits\Captchable;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Nixpal\DomainTools\DomainName;

/**
 * A lookup asks for the captcha (ours, ALTCHA, when none is chosen: owner 2026-10-23), counts towards the public pages'
 * shared limit (PublicLookups), and a link (?domain=) fills the name in without asking: one press, one lookup.
 *
 * Public whois (D8a): who has a name, from its registry (RDAP, else port 43 with personal data masked; .gr and .ελ from
 * the registry's WHOIS API, G6 of grepp). Registrar, dates, status, nameservers, DNSSEC: what the registry publishes. At most 10 per 10 minutes per
 * address; answers cached an hour.
 */
class Whois extends Component
{
    use Captchable;

    public const LIMIT = 10;

    #[Url('domain', except: '')]
    public string $query = '';

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $answer = null;

    #[Locked]
    public ?string $error = null;

    #[Locked]
    public ?string $fqdn = null;

    /** The captchas that send their form by itself (reCAPTCHA v3 calls submit()): the lookup. */
    public function submit(): void
    {
        $this->lookup();
    }

    public function lookup(): void
    {
        // One solution, one lookup: a captcha found valid earlier in this request counts no more (code review).
        $this->is_valid = false;
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
        $fqdn = self::registrable($fqdn);
        $key = 'domain-whois-hits:' . VisitorKey::of(request());
        if (RateLimiter::tooManyAttempts($key, self::LIMIT)) {
            $this->error = __('domains.shop.too_many');

            return;
        }
        // Not a script's: the captcha (a solution counts once), then everyone's limit together.
        $this->requireCaptchaOrAltcha();
        RateLimiter::hit($key, 600);
        if (!PublicLookups::take()) {
            $this->error = __('domains.shop.busy');

            return;
        }
        $this->fqdn = $fqdn;
        $result = app(DomainLookup::class)->whois($fqdn);
        $this->answer = [
            'status' => $result['status'],
            'record' => $result['record'] === null ? null : (array) $result['record'],
            'text' => $result['text'],
        ];
    }

    /**
     * The registered name of what was typed (review R5): «mail.google.com» → google.com; a known suffix of more labels
     * (a TLD we list, such as co.uk) keeps one label more.
     */
    public static function registrable(string $fqdn): string
    {
        $labels = explode('.', $fqdn);
        if (count($labels) <= 2) {
            return $fqdn;
        }
        // The .gr registry's second levels are its own, fixed (ADV-6 of #197), listed as TLDs here or not.
        $suffixes = array_merge(['com.gr', 'net.gr', 'org.gr', 'edu.gr', 'gov.gr'],
            DomainTld::query()->where('tld', 'like', '%.%')->pluck('tld')->map(fn ($t) => (string) DomainName::toAscii($t))->all());
        $lastTwo = implode('.', array_slice($labels, -2));

        return in_array($lastTwo, $suffixes, true) ? implode('.', array_slice($labels, -3)) : $lastTwo;
    }

    public function render()
    {
        return view('domain-shop.whois')->layoutData(['title' => __('domains.shop.whois_title')]);
    }
}
