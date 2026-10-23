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

    protected function getViewData(): array
    {
        return ['ours' => $this->answer === null ? null : $this->ours()];
    }

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
        // Before anything is asked: Filament's own check runs after this mount (code review 1: ?domain= looked up first).
        abort_unless(static::canAccess(), 403);
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

    /** Ours, when it is: the domain's page here (any status, the newest first). Not callable from the page. */
    protected function ours(): ?Domain
    {
        if ($this->fqdn === null) {
            return null;
        }

        // Kept as punycode, as here (Domain::booted).
        return Domain::query()->where('fqdn', $this->fqdn)->latest('id')->first();
    }
}
