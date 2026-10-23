<?php

namespace App\Admin\Pages;

use App\Livewire\DomainShop\Whois;
use App\Models\Domain;
use App\Services\Domains\Lookup\DomainLookup;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Nixpal\DomainTools\DomainName;

/**
 * WHOIS for staff (WHMCS «Utilities → WHOIS Lookup», ~80 a month at myip): any name, from its registry as the public
 * page asks it (DomainLookup), with a link to it when it is one of ours. Staff who may see the domains; 60 lookups per
 * 10 minutes each (the public page: 10 per address).
 */
class WhoisLookup extends Page
{
    public const LIMIT = 60;

    protected static string|\UnitEnum|null $navigationGroup = 'Utilities';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-search-eye-line';

    protected static ?string $navigationLabel = 'WHOIS Lookup';

    protected static ?string $title = 'WHOIS Lookup';

    protected static string $routePath = 'whois';

    protected string $view = 'admin.pages.whois-lookup';

    #[Url('domain', except: '')]
    public string $query = '';

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $answer = null;

    #[Locked]
    public ?string $error = null;

    #[Locked]
    public ?string $fqdn = null;

    public static function canAccess(): bool
    {
        return (bool) Auth::guard('staff')->user()?->hasPermission('admin.domains.viewAny');
    }

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
        $this->fqdn = null;
        $typed = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', mb_strtolower(trim(mb_substr($this->query, 0, 255))));
        $typed = trim(preg_replace('/^www\./', '', (string) preg_replace('#[/?\#:].*$#', '', (string) $typed)), ' .');
        $fqdn = DomainName::toAscii($typed);
        if ($fqdn === null || !DomainName::validDomain($fqdn)) {
            $this->error = __('domains.shop.not_a_full_name');

            return;
        }
        $fqdn = Whois::registrable($fqdn);
        $key = 'admin-whois:' . Auth::guard('staff')->id();
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

    /** Ours, when it is: the domain's page here (any status, the newest first). */
    public function ours(): ?Domain
    {
        if ($this->fqdn === null) {
            return null;
        }

        // Kept as typed (an IDN in Unicode or punycode): either.
        $unicode = idn_to_utf8($this->fqdn, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $this->fqdn;

        return Domain::query()->whereIn('fqdn', array_unique([$this->fqdn, $unicode]))->latest('id')->first();
    }
}
