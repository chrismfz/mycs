<?php

namespace App\Admin\Pages;

use App\Admin\Resources\DomainResource;
use App\Models\Domain;
use App\Models\DomainOwnerChange;
use App\Models\DomainRegistrarConnection;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\RegistrarBalances;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\AvailabilityResult;
use App\Support\Redact;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * Our account at the .gr registry (G8, owner 2026-10-23), one page: its balance and whether it may be charged; the domains
 * that need a look (an EETT status, a change of holder waiting or with no answer); a check of names with the registry's
 * «under restrictions»; our glue hosts as last read; and a link to grweb for what EPP does not do. Nothing here writes.
 */
class GrRegistry extends Page
{
    /** Names one check takes (the registry takes more; staff check a few at a time). */
    public const CHECK_MAX = 10;

    /** Checks per staff member per 10 minutes. */
    public const CHECK_LIMIT = 30;

    /** grweb, the registry's web panel: what EPP does not do (the holder's PDF, the registrar's IP, users, statistics). */
    public const GRWEB = 'https://grweb.ics.forth.gr/';

    protected static string|\UnitEnum|null $navigationGroup = 'Utilities';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-government-line';

    protected static ?string $navigationLabel = '.gr Registry';

    protected static ?string $title = '.gr Registry';

    protected static string $routePath = 'gr-registry';

    protected string $view = 'admin.pages.gr-registry';

    /** One name a line, as typed. */
    public string $names = '';

    /** @var list<array{fqdn: string, available: bool, restricted: bool, reason: ?string}> */
    #[Locked]
    public array $checked = [];

    #[Locked]
    public ?string $checkError = null;

    public static function canAccess(): bool
    {
        return (bool) Auth::guard('staff')->user()?->hasPermission('admin.domains.viewAny');
    }

    private static function canManage(): bool
    {
        return (bool) Auth::guard('staff')->user()?->hasPermission('admin.domains.manage');
    }

    /** @return Collection<int, DomainRegistrarConnection> the .gr registry's connections (one, as a rule) */
    public static function connections(): Collection
    {
        return DomainRegistrarConnection::query()->where('registrar', 'grepp')->orderByDesc('is_active')->orderBy('id')->get();
    }

    /** «Read now»: its balance asked of the registry (the hourly read's, at once). */
    public function readBalance(int $connection): void
    {
        abort_unless(self::canManage(), 403);
        $connection = self::connections()->firstWhere('id', $connection) ?? abort(404);
        app(RegistrarBalances::class)->refresh($connection);
        $error = $connection->refresh()->balance['error'] ?? null;
        Notification::make()->{$error ? 'danger' : 'success'}()->title($error ?: __('domains.gr_registry.balance_read'))->send();
    }

    /** The names checked at the registry, «under restrictions» told apart (geographic terms, .gov.gr). */
    public function check(): void
    {
        $this->checked = [];
        $this->checkError = null;
        $names = collect(preg_split('/[\s,;]+/', mb_strtolower(mb_substr($this->names, 0, 2000))) ?: [])
            ->map(fn ($name) => trim((string) preg_replace('#^[a-z][a-z0-9+.-]*://(www\.)?#', '', (string) $name), ' ./'))
            // A .ελ typed in punycode (from an address bar) is one too (ADV-2 of #394): sent as typed, the registrar's
            // Rules::name turns it to Unicode.
            ->filter(function ($name) {
                $plain = str_contains($name, 'xn--') ? (string) (idn_to_utf8($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $name) : $name;

                return $name !== '' && (str_ends_with($plain, '.gr') || str_ends_with($plain, '.ελ'));
            })->unique()->values();
        if ($names->isEmpty()) {
            $this->checkError = __('domains.gr_registry.check_none');

            return;
        }
        if ($names->count() > self::CHECK_MAX) {
            $this->checkError = __('domains.gr_registry.check_too_many', ['count' => self::CHECK_MAX]);

            return;
        }
        $connection = self::connections()->first(fn (DomainRegistrarConnection $c) => $c->is_active && $c->isUsable());
        if ($connection === null) {
            $this->checkError = __('domains.gr_registry.no_connection');

            return;
        }
        $key = 'gr-registry-check:' . Auth::guard('staff')->id();
        if (RateLimiter::tooManyAttempts($key, self::CHECK_LIMIT)) {
            $this->checkError = __('domains.shop.too_many');

            return;
        }
        RateLimiter::hit($key, 600);
        try {
            $factory = app(DomainRegistrarFactory::class);
            $registrar = $factory->for($connection);
            if (!$registrar instanceof GrEppRegistrar) {
                throw new \RuntimeException(__('domains.gr_registry.no_connection'));
            }
            $results = $registrar->checkAvailabilityMany($names->all(), $factory->credentialsFor($connection));
        } catch (Throwable $e) {
            // Its credentials hidden, as the balance's errors (review ADV-1 of #394): the text goes to the browser.
            report($e);
            $this->checkError = mb_substr(Redact::values(Redact::text($e->getMessage()), array_filter($connection->credentials(), 'is_string')), 0, 300);

            return;
        }
        $this->checked = collect($results)->map(fn (AvailabilityResult $result) => [
            'fqdn' => $result->fqdn,
            'available' => $result->available,
            'restricted' => str_starts_with((string) $result->reason, 'restricted: '),
            'reason' => $result->reason === null ? null : (string) preg_replace('/^restricted: /', '', $result->reason),
        ])->values()->all();
    }

    protected function getViewData(): array
    {
        $connections = self::connections();

        return [
            'connections' => $connections->map(fn (DomainRegistrarConnection $connection) => [
                'id' => $connection->id,
                'label' => $connection->label,
                'active' => $connection->is_active,
                'balance' => $connection->balance ?? [],
                'problem' => RegistrarBalances::problemText($connection),
            ])->all(),
            'canManage' => self::canManage(),
            'toCheck' => $this->toCheck($connections),
            'changes' => DomainOwnerChange::query()->with('domain:id,fqdn')
                ->whereIn('status', [DomainOwnerChange::STATUS_AWAITING_EETT, DomainOwnerChange::STATUS_UNKNOWN, DomainOwnerChange::STATUS_REFUSED,
                    DomainOwnerChange::STATUS_SENDING, DomainOwnerChange::STATUS_DUE])
                ->latest('id')->limit(50)->get(),
            'hosts' => $this->hosts(),
            'grweb' => self::GRWEB,
        ];
    }

    /**
     * Domains with a status of the EETT (a hold, a debt, a change not allowed, an approval awaited), as the last sync read
     * them (meta.registrar, G8-3): the page asks the registry nothing. Matched in SQL, not by «not null»: MariaDB reads a JSON
     * null as the text «null», so empty ones would fill the limit.
     *
     * @param  Collection<int, DomainRegistrarConnection>  $connections
     * @return Collection<int, array{domain: Domain, url: string, statuses: non-empty-list<array{code: string, text: array<mixed>|string}>}>
     */
    private function toCheck(Collection $connections): Collection
    {
        if ($connections->isEmpty()) {
            return collect();
        }

        return Domain::query()->with('client.primaryContact')->where('meta->registrar->regulator_statuses', 'like', '%egulator%')
            ->whereNotIn('status', ['transferred_away', 'cancelled', 'deleted'])->orderBy('fqdn')->limit(100)->get()
            ->map(function (Domain $domain) {
                $codes = array_values(array_filter(array_map('trim', explode(',', (string) ($domain->meta['registrar']['regulator_statuses'] ?? '')))));

                return [
                    'domain' => $domain,
                    'url' => DomainResource::getUrl('view', ['record' => $domain]),
                    'statuses' => array_map(fn (string $code) => ['code' => $code,
                        'text' => trans()->has('domains.registry_status.' . $code) ? __('domains.registry_status.' . $code) : $code], $codes),
                ];
            })->filter(fn (array $row) => $row['statuses'] !== [])->values();
    }

    /**
     * Our glue hosts (ns1.myip.gr …) as the domains' pages last read them (meta.child_hosts): changed there.
     *
     * @return Collection<int, array{host: non-empty-string, addresses: list<string>, domain: Domain, url: string, read_at: mixed}>
     */
    private function hosts(): Collection
    {
        // Read with none (hosts: []) never fill the limit (ADV-3 of #394): sifted here, not by a JSON «like», which
        // MariaDB and SQLite read differently; .gr and .ελ, a few hundred at most.
        return Domain::query()->whereNotNull('meta->child_hosts')
            ->where(fn ($q) => $q->whereIn('tld', ['gr', 'ελ'])->orWhere('tld', 'like', '%.gr'))
            ->orderBy('fqdn')->lazy(200)
            ->filter(fn (Domain $domain) => ($domain->meta['child_hosts']['hosts'] ?? []) !== [])->take(50)->collect()
            ->flatMap(fn (Domain $domain) => collect($domain->meta['child_hosts']['hosts'] ?? [])->map(fn ($host) => [
                'host' => (string) ($host['name'] ?? $host['host'] ?? ''),
                'addresses' => array_values(array_map('strval', (array) ($host['addresses'] ?? []))),
                'domain' => $domain,
                'url' => DomainResource::getUrl('view', ['record' => $domain]),
                'read_at' => $domain->meta['child_hosts']['read_at'] ?? null,
            ]))->filter(fn (array $row) => $row['host'] !== '')->values()->toBase();
    }
}
