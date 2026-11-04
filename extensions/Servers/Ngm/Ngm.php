<?php

namespace MyCS\Extensions\Servers\Ngm;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Contracts\ReadsServiceUsage;
use App\Exceptions\ServerRefused;
use App\Models\Service;
use App\Rules\Domain;
use App\Support\Services\ServiceUsageRead;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ngm, our own hosting panel (owner 2026-11-04: ngm.myip.gr, ngm2, ngm-debian), as our WHMCS module for it drives its
 * `/api/v1`: an account per service (its package, its domain as the primary site), suspend and unsuspend, terminate (ngm
 * keeps its backups), a package change, its password, single sign-on into the panel and usage. Every action that fails is
 * left open for staff in the Pending Module Actions, ngm's reason with it.
 */
#[ExtensionMeta(
    name: 'ngm',
    description: 'ngm hosting panel: an account per service, over its API',
    version: 'builtin',
    author: 'MyCS',
)]
class Ngm extends Server implements ReadsServiceUsage
{
    /** The account the service has on ngm (made by this module; an imported one is on services.username, as WHMCS). */
    public const USERNAME = 'ngm_username';

    /** A name asked for and not answered yet: a retry asks for the same one, never makes a second account. */
    public const PENDING = 'ngm_pending_username';

    public function getConfig($values = []): array
    {
        return [
            ['name' => 'host', 'type' => 'text', 'label' => 'Hostname', 'placeholder' => 'ngm.example.com', 'required' => true,
                'description' => 'https and the API port (' . NgmApi::DEFAULT_PORT . ') unless given: https://host:port, or http:// for a lab host.'],
            ['name' => 'token', 'type' => 'password', 'label' => 'API token', 'required' => true, 'encrypted' => true,
                'description' => 'An admin token with the scopes read and accounts (ngm: /ui/tokens), and api.account_writes on.'],
        ];
    }

    /** As the WHMCS module's options: the ngm package by name, and the primary site's PHP version. */
    public function getProductConfig($values = []): array
    {
        return [
            ['name' => 'package', 'type' => 'text', 'label' => 'ngm package', 'description' => 'Its name, as the panel shows it (GET /api/v1/packages). Blank: none.'],
            ['name' => 'php', 'type' => 'text', 'label' => 'PHP version', 'placeholder' => '8.3', 'description' => 'Of the primary site. Blank: the host\'s default.'],
        ];
    }

    public function getCheckoutConfig()
    {
        return [['name' => 'domain', 'type' => 'text', 'label' => 'Domain', 'required' => true, 'validation' => [new Domain, 'required'], 'placeholder' => 'domain.com']];
    }

    public function testConfig(): bool|string
    {
        try {
            $data = $this->api()->expect('GET', 'ping', null, [200]);
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return !empty($data['ok']) ? true : 'ngm: the ping did not answer ok';
    }

    public function api(int $timeout = 30): NgmApi
    {
        return new NgmApi((string) $this->config('host'), (string) $this->config('token'), $timeout);
    }

    /**
     * ngm's usernames are strict (`^[a-z][a-z0-9_]{0,31}$`), as the WHMCS module cleans them: lower case, only letters,
     * digits and `_`, a letter first, 32 at most.
     */
    public static function sanitize(string $raw): string
    {
        $name = (string) preg_replace('/[^a-z0-9_]/', '', strtolower($raw));
        if ($name === '' || !ctype_alpha($name[0])) {
            $name = 'u' . $name;
        }

        return substr($name, 0, 32);
    }

    /** The account's username: kept on the service. */
    private static function username(Service $service, array $properties): ?string
    {
        $username = trim((string) ($properties[self::USERNAME] ?? $service->username ?? ''));

        return $username !== '' ? $username : null;
    }

    private static function path(string $username, string $action = ''): string
    {
        return 'accounts/' . rawurlencode($username) . ($action !== '' ? "/{$action}" : '');
    }

    /**
     * The other services on this ngm with this name, as their username or one of these properties: on it by their own
     * server or, with none, by their product's (as Centova's); names compared trimmed, in any case.
     *
     * @param  list<string>  $keys
     * @return list<Service>
     */
    private static function claimants(Service $service, string $name, array $keys = [self::USERNAME, self::PENDING]): array
    {
        $serverId = $service->provisioningServer()?->id;
        if ($serverId === null) {
            return [];
        }
        $name = strtolower(trim($name));
        $same = fn (mixed $value) => is_string($value) && strtolower(trim($value)) === $name;

        return Service::query()->whereKeyNot($service->id)
            ->where(fn ($query) => $query->where('server_id', $serverId)
                ->orWhere(fn ($query) => $query->whereNull('server_id')->whereHas('product', fn ($product) => $product->where('server_id', $serverId))))
            ->with(['properties' => fn ($property) => $property->whereIn('key', $keys)])
            ->get()
            ->filter(fn (Service $other) => $same($other->username) || $other->properties->contains(fn ($property) => $same($property->value)))
            ->values()->all();
    }

    /** Another live service here has this account: never acted on from this one. */
    private static function claimedByAnotherLive(Service $service, string $username): bool
    {
        return collect(self::claimants($service, $username, [self::USERNAME]))
            ->contains(fn (Service $other) => !in_array($other->status, Service::ENDED_STATUSES, true));
    }

    /**
     * The service's own account: ngm has it by this exact name, and no other live service here claims the same name (an
     * imported or typed name that is another customer's). Every action on an account goes through here.
     */
    private static function owned(NgmApi $api, Service $service, array $properties): string
    {
        $username = self::named($service, $properties);
        if (!self::exists($api, $username)) {
            throw new Exception("ngm: no account «{$username}» on this server: check the service's username");
        }

        return $username;
    }

    /**
     * The service's account name, when no other live service here claims it. A create whose answer was lost (a name asked
     * for, none kept) is named for staff, never «not created»: ngm may have the account (review of #630).
     */
    private static function named(Service $service, array $properties): string
    {
        $pending = trim((string) ($properties[self::PENDING] ?? ''));
        $username = self::username($service, $properties)
            ?? throw new Exception($pending !== '' ? "ngm: the account «{$pending}» was asked for and its answer was lost: check ngm for it (retry the create) before anything is done"
                : 'Service has not been created');
        if (self::claimedByAnotherLive($service, $username)) {
            throw new Exception("ngm: another service here has the account «{$username}»: check them before anything is done");
        }

        return $username;
    }

    /**
     * ngm's record of the account (200), or null when it has none by this name (404); anything else is not known.
     *
     * @return array<string, mixed>|null
     */
    private static function account(NgmApi $api, string $username): ?array
    {
        [$status, $data] = $api->request('GET', self::path($username));

        return match ($status) {
            200 => $data,
            404 => null,
            default => throw NgmApi::failure('GET', self::path($username), $status, $data),
        };
    }

    private static function exists(NgmApi $api, string $username): bool
    {
        return self::account($api, $username) !== null;
    }

    /**
     * The names a new account may take: the service's own (an imported or typed one, cleaned as WHMCS did), else one from
     * its domain, then with a digit.
     *
     * @return list<string>
     */
    private static function candidates(Service $service, array $properties): array
    {
        $given = trim((string) $service->username);
        if ($given !== '') {
            return [self::sanitize($given)];
        }
        $domain = strtolower(trim((string) ($properties['domain'] ?? $service->domain ?? '')));
        $stub = substr(self::sanitize(Str::before(Str::ascii($domain), '.') ?: 'user'), 0, 12);

        return array_merge([$stub], array_map(fn (int $i) => $stub . $i, range(1, 9)));
    }

    /**
     * An account by this name that this service's earlier attempt made (its answer lost, or its provisioning failed after
     * the record, which ngm leaves half made): no other service here has the name, ngm has it by that exact name, and ngm
     * made it after this service asked for it (review of #630: never one made by hand meanwhile, days later). Clocks
     * may differ by a few minutes.
     */
    private static function ownRetry(NgmApi $api, Service $service, string $username): bool
    {
        $asked = $service->properties()->where('key', self::PENDING)->value('created_at');
        $account = self::claimants($service, $username) === [] ? self::account($api, $username) : null;
        $made = is_string($account['created_at'] ?? null) ? rescue(fn () => Carbon::parse($account['created_at']), null, false) : null;

        return $asked !== null && $made !== null && $made->gte(Carbon::parse($asked)->subMinutes(5));
    }

    /**
     * The account (POST /accounts), with the product's package and PHP version and the service's domain as its primary
     * site. Idempotent and never someone else's: the name and password are kept before they are asked for; ngm's «taken»
     * (409) is this service's own account only after its own earlier attempt (ownRetry, the WHMCS module's check and
     * ngm's creation time), else that name is given up for the next free one (ngm's database names can clash while the
     * account name is free: review of #630). ngm's refusal takes the kept name off; no answer keeps it.
     */
    public function createServer(Service $service, $settings, $properties)
    {
        if (filled($properties[self::USERNAME] ?? null)) {
            throw new Exception('ngm: the service already has an account');
        }
        $api = $this->api(120);
        // Kept (encrypted) before it is asked for: a lost answer still knows the password it was made with.
        $password = (string) $service->password;
        if ($password === '') {
            $password = Str::password(20);
            $service->forceFill(['password' => $password])->save();
        }
        // A service suspended before its account was made gets it dark (ngm's create-disabled), as WHMCS does.
        $body = ['password' => $password, 'enabled' => $service->status !== Service::STATUS_SUSPENDED];
        foreach (['package' => $settings['package'] ?? '', 'domain' => $properties['domain'] ?? $service->domain ?? '', 'php' => $settings['php'] ?? ''] as $key => $value) {
            if (trim((string) $value) !== '') {
                $body[$key] = $key === 'domain' ? strtolower(trim((string) $value)) : trim((string) $value);
            }
        }
        $ask = fn (string $username) => $api->request('POST', 'accounts', ['username' => $username] + $body);

        $pending = trim((string) ($properties[self::PENDING] ?? ''));
        if ($pending !== '') {
            [$status, $data] = $ask($pending);
            if ($status === 201) {
                return $this->made($service, $pending, $data);
            }
            if ($status === 409 && self::ownRetry($api, $service, $pending)) {
                return $this->made($service, $pending, ['warning' => "adopted «{$pending}», made by an earlier attempt: check in the panel that it is complete"]);
            }
            self::refused($service, $status, $data);
        }

        $taken = null;
        foreach (self::candidates($service, $properties) as $candidate) {
            if (self::claimants($service, $candidate) !== [] || self::exists($api, $candidate)) {
                continue;
            }
            $service->properties()->where('key', self::PENDING)->delete();
            $service->properties()->create(['key' => self::PENDING, 'name' => 'ngm account (being created)', 'value' => $candidate]);
            [$status, $data] = $ask($candidate);
            if ($status === 201) {
                return $this->made($service, $candidate, $data);
            }
            if ($status !== 409) {
                self::refused($service, $status, $data);
            }
            $service->properties()->where('key', self::PENDING)->delete();
            $taken = NgmApi::failure('POST', 'accounts', $status, $data);
        }

        throw $taken ?? new Exception(filled($service->username)
            ? 'ngm: the service\'s username «' . self::sanitize((string) $service->username) . '» is taken on this server: change it, or create the account by hand'
            : 'ngm: no free username found from the service\'s domain: create the account by hand');
    }

    /**
     * Not made, nor taken by name (409 is the caller's): ngm's refusal takes the kept name off (nothing was made under it,
     * and a refusal tied to it must not repeat for ever); no answer, or an error of ngm's own, keeps it.
     *
     * @param  array<string, mixed>  $data
     */
    private static function refused(Service $service, int $status, array $data): never
    {
        $failure = NgmApi::failure('POST', 'accounts', $status, $data);
        if ($failure instanceof ServerRefused) {
            $service->properties()->where('key', self::PENDING)->delete();
        }

        throw $failure;
    }

    /** The account kept on the service; ngm's warnings (its primary site not made, say) told to staff. */
    private function made(Service $service, string $username, array $data): bool
    {
        $service->properties()->updateOrCreate(['key' => self::USERNAME], ['name' => 'ngm account', 'value' => $username]);
        $service->properties()->where('key', self::PENDING)->delete();
        $service->forceFill(['username' => $username])->save();
        self::warnings($service, 'create', $data);

        return true;
    }

    /** ngm's non-fatal `warning` / `warnings` (an action done, a part of it not): reported, as the WHMCS module logs them. */
    private static function warnings(Service $service, string $action, array $data): void
    {
        $notes = array_values(array_filter(array_merge([$data['warning'] ?? null], is_array($data['warnings'] ?? null) ? $data['warnings'] : []),
            fn (mixed $note) => is_string($note) && trim($note) !== ''));
        if ($notes !== []) {
            report(new Exception("ngm {$action} of service #{$service->id}, done with warnings: " . implode('; ', $notes)));
        }
    }

    /** Suspend, with the service's reason (ngm keeps it on the account). */
    public function suspendServer(Service $service, $settings, $properties)
    {
        $api = $this->api();
        $username = self::owned($api, $service, $properties);
        $reason = trim((string) $service->suspend_reason);
        self::warnings($service, 'suspend', $api->expect('POST', self::path($username, 'suspend'), $reason !== '' ? ['reason' => $reason] : [], [200]));

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $api = $this->api();
        $username = self::owned($api, $service, $properties);
        self::warnings($service, 'unsuspend', $api->expect('POST', self::path($username, 'unsuspend'), [], [200]));

        return true;
    }

    /**
     * DELETE /accounts/{user}: ngm terminates it and keeps its backups (a restore is still possible there). An account
     * already gone is done only for one this module made; an imported name ngm does not have is staff's to check. The
     * username stays on the service, so staff can find its backups.
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        $api = $this->api(120);
        $username = self::named($service, $properties);
        if (self::exists($api, $username)) {
            [$status, $data] = $api->request('DELETE', self::path($username));
            if (!in_array($status, [200, 404], true)) {
                throw NgmApi::failure('DELETE', self::path($username), $status, $data);
            }
        } elseif (!filled($properties[self::USERNAME] ?? null)) {
            throw new Exception("ngm: no account «{$username}» on this server: check the service's username");
        }
        $service->properties()->where('key', self::USERNAME)->delete();

        return true;
    }

    /**
     * A product change: the new product's package (by name). A downgrade below the account's usage still lands, ngm
     * says so in a warning (reported).
     */
    public function upgradeServer(Service $service, $settings, $properties)
    {
        $package = trim((string) ($settings['package'] ?? ''));
        if ($package === '') {
            throw new Exception('ngm: the product has no package');
        }
        $api = $this->api();
        $username = self::owned($api, $service, $properties);
        self::warnings($service, 'package change', $api->expect('POST', self::path($username, 'package'), ['package' => $package], [200]));

        return true;
    }

    /**
     * The account's panel (and SSH/SFTP) password, never its mailboxes' or FTP accounts'; kept once ngm took it. ngm's
     * password policy refusing it is the customer's to read.
     */
    public function changePassword(Service $service, $settings, $properties, string $password, bool $mysqlToo = false): bool
    {
        $api = $this->api();
        $username = self::owned($api, $service, $properties);
        $answer = $api->expect('POST', self::path($username, 'password'), ['password' => $password], [200]);
        $service->forceFill(['password' => $password])->save();
        self::warnings($service, 'password change', $answer);

        return true;
    }

    /**
     * Into the panel, signed in: ngm's single-use ticket (POST /accounts/{user}/sso), as WHMCS's «Log in to panel». Staff's
     * «Log in to panel» (Admin\ServicePanelLoginController, the only caller that passes a fourth argument) gets ngm's
     * admin ticket, as WHMCS's AdminSingleSignOn: staff land impersonating the account (ngm's banner and audit), and a
     * suspended one opens too, where a customer's ticket is refused (review of #630).
     */
    public function getLoginUrl(Service $service, $settings, $properties, ?string $app = null): string
    {
        $staff = func_num_args() > 3;
        $api = $this->api(15);
        $username = self::owned($api, $service, $properties);
        [$status, $data] = $api->request('POST', self::path($username, 'sso'), $staff ? ['admin' => true] : []);
        if ($status === 403 && str_contains(strtolower((string) ($data['error'] ?? '')), 'sso api is disabled')) {
            throw new Exception('ngm: single sign-on is not enabled on this host (api.allow_sso)');
        }
        $url = $data['url'] ?? null;
        if ($status !== 200 || !is_string($url) || !preg_match('#^https?://#i', $url)) {
            throw $status === 200 ? new Exception('ngm: the single sign-on answer has no address') : NgmApi::failure('POST', self::path($username, 'sso'), $status, $data);
        }

        return $url;
    }

    public function getActions(Service $service, $settings, $properties): array
    {
        $username = self::username($service, $properties);
        if ($username === null) {
            return [];
        }

        $actions = [
            ['label' => __('services.ngm.login'), 'type' => 'button', 'sso' => true, 'function' => 'getLoginUrl'],
            ['type' => 'password', 'function' => 'changePassword'],
            ['label' => __('services.ngm.username'), 'type' => 'text', 'text' => $username],
        ];
        $panel = rescue(fn () => $this->api()->panelUrl(), null, false);

        return $panel !== null ? [...$actions, ['label' => __('services.ngm.panel'), 'type' => 'text', 'text' => $panel]] : $actions;
    }

    /**
     * Usage of every account here, from one GET /usage (ngm's MB; a limit of -1 is none; bandwidth is web traffic only);
     * a service whose account ngm does not list is left out.
     *
     * @param  list<Service>  $services
     * @return array<int, ServiceUsageRead>
     */
    public function usage(array $services): array
    {
        $rows = collect($this->api(60)->expect('GET', 'usage', null, [200])['accounts'] ?? [])
            ->filter(fn (mixed $row) => is_array($row) && is_string($row['username'] ?? null))
            ->keyBy(fn (array $row) => $row['username']);
        $used = fn (mixed $value) => is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
        $limit = fn (mixed $value) => is_numeric($value) && (int) $value > 0 ? (int) $value : null;
        $read = [];
        foreach ($services as $service) {
            $username = self::username($service, $service->properties->pluck('value', 'key')->all());
            $row = $username !== null ? $rows->get($username) : null;
            if ($row === null) {
                continue;
            }
            $read[$service->id] = new ServiceUsageRead(
                diskUsedMb: $used($row['disk_used_mb'] ?? null),
                diskLimitMb: $limit($row['disk_limit_mb'] ?? null),
                bandwidthUsedMb: $used($row['bandwidth_used_mb'] ?? null),
                bandwidthLimitMb: $limit($row['bandwidth_limit_mb'] ?? null),
            );
        }

        return $read;
    }
}
