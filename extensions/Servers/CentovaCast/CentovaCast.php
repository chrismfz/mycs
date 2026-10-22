<?php

namespace MyCS\Extensions\Servers\CentovaCast;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Contracts\ReadsServiceUsage;
use App\Exceptions\ServerRefused;
use App\Models\Service;
use App\Support\Services\ServiceUsageRead;
use Exception;
use Illuminate\Support\Str;

/**
 * Centova Cast streaming accounts (owner 2026-10-22: myip's 9 radio stations on stream.myip.gr), as Centova's own WHMCS
 * module does it: an account from a Centova template per service, suspend by disabling it, the panel, its password, plan
 * limits and usage. Terminate disables by default (the station's media kept); deleting is the product's explicit choice.
 */
#[ExtensionMeta(
    name: 'Centova Cast',
    description: 'Centova Cast streaming accounts (Shoutcast, Icecast)',
    version: 'builtin',
    author: 'MyCS',
)]
class CentovaCast extends Server implements ReadsServiceUsage
{
    /** The service's mark that its suspend disabled the account (and how its stream was), so unsuspend undoes only that. */
    public const DISABLED = 'centova_disabled_by_us';

    public const PENDING = 'centova_pending_username';

    public const USERNAME = 'centova_username';

    public const DISABLE_ONLY = 'disable_only';

    public const DELETE = 'delete';

    public function getConfig($values = []): array
    {
        return [
            ['name' => 'host', 'type' => 'text', 'label' => 'Centova Cast URL', 'placeholder' => 'https://stream.example.com:2199', 'required' => true],
            ['name' => 'username', 'type' => 'text', 'label' => 'Admin username', 'default' => 'admin', 'required' => true],
            ['name' => 'password', 'type' => 'password', 'label' => 'Admin password', 'required' => true, 'encrypted' => true],
        ];
    }

    /** As Centova's WHMCS module (its products set only the template and «start server»: the limits are the template's). */
    public function getProductConfig($values = []): array
    {
        $either = [['value' => '', 'label' => 'As the template'], ['value' => '1', 'label' => 'Enabled'], ['value' => '0', 'label' => 'Disabled']];

        return [
            ['name' => 'template', 'type' => 'text', 'label' => 'Account template name', 'required' => true, 'description' => 'Create it in Centova Cast.'],
            ['name' => 'maxclients', 'type' => 'number', 'label' => 'Max listeners', 'description' => 'Blank: as the template.'],
            ['name' => 'maxbitrate', 'type' => 'number', 'label' => 'Max bit rate (kbps)', 'description' => 'Blank: as the template.'],
            ['name' => 'transferlimit', 'type' => 'number', 'label' => 'Data transfer limit (MB/month)', 'description' => 'Blank: as the template.'],
            ['name' => 'diskquota', 'type' => 'number', 'label' => 'Disk quota (MB)', 'description' => 'Blank: as the template.'],
            ['name' => 'mountlimit', 'type' => 'number', 'label' => 'Mount point limit', 'description' => 'Blank: as the template.'],
            ['name' => 'autostart', 'type' => 'checkbox', 'label' => 'Start the stream when created (if AutoDJ is off)', 'default' => true],
            ['name' => 'allowproxy', 'type' => 'select', 'label' => 'Stream proxy', 'default' => '', 'options' => $either],
            ['name' => 'usesource', 'type' => 'select', 'label' => 'AutoDJ', 'default' => '', 'options' => [
                ['value' => '', 'label' => 'As the template'], ['value' => '1', 'label' => 'Enabled'], ['value' => '0', 'label' => 'Allowed, off by default'], ['value' => '2', 'label' => 'Not allowed'],
            ]],
            ['name' => 'terminate_mode', 'type' => 'select', 'label' => 'On terminate', 'default' => self::DISABLE_ONLY, 'options' => [
                ['value' => self::DISABLE_ONLY, 'label' => 'Disable the account only (safe: its media and settings are kept)'],
                ['value' => self::DELETE, 'label' => 'Delete the account, its media and logs (irreversible)'],
            ]],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->api()->system('version');
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    private function api(int $timeout = 30): CentovaApi
    {
        return new CentovaApi((string) $this->config('host'), trim((string) ($this->config('username') ?: 'admin')), (string) $this->config('password'), $timeout);
    }

    /** The account's username: kept on the service (an imported one on services.username, as WHMCS). */
    private static function username(Service $service, array $properties): ?string
    {
        $username = trim((string) ($properties['centova_username'] ?? $service->username ?? ''));

        return $username !== '' ? $username : null;
    }

    /**
     * The account as Centova has it (its exact name), or [].
     *
     * @return array<string, mixed>
     */
    private static function account(CentovaApi $api, string $username): array
    {
        // A keyword filter: every page read, never only the first hundred matches (ADV-6 of #329).
        // A page seen already ends it: a Centova that ignores `start` is asked twice, not a hundred times (R2-5 of #329).
        $seen = [];
        for ($start = 0; $start < 10000; $start += 100) {
            $rows = CentovaApi::rows($api->system('listaccounts', ['start' => $start, 'limit' => 100, 'filter' => $username]));
            foreach ($rows as $row) {
                if (($row['username'] ?? null) === $username) {
                    return $row;
                }
            }
            $page = md5(serialize(array_column($rows, 'username')));
            if (count($rows) < 100 || isset($seen[$page])) {
                break;
            }
            $seen[$page] = true;
        }

        return [];
    }

    /**
     * The service's own account: Centova has it by this exact name, and no other live service here claims the same name
     * (an import or a typed name that is another station's). Every action on an account goes through here.
     */
    private static function owned(CentovaApi $api, Service $service, array $properties): string
    {
        $username = self::username($service, $properties) ?? throw new Exception('Service has not been created');
        $others = collect(self::claimants($service, $username, [self::USERNAME]))
            ->contains(fn (Service $other) => !in_array($other->status, [Service::STATUS_CANCELLED, Service::STATUS_TERMINATED], true));
        if ($others) {
            throw new Exception("Centova Cast: another service here has the account «{$username}»: check them before anything is done");
        }
        if (self::account($api, $username) === []) {
            throw new Exception("Centova Cast: no account «{$username}» on this server: check the service's username");
        }

        return $username;
    }

    /**
     * The product's limits for provision and reconfigure (a blank one: the template's).
     *
     * @return array<string, int|string>
     */
    private static function limits(array $settings): array
    {
        $limits = [];
        foreach (['maxclients', 'maxbitrate', 'transferlimit', 'diskquota', 'mountlimit', 'allowproxy', 'usesource'] as $key) {
            $value = trim((string) ($settings[$key] ?? ''));
            if ($value !== '' && ctype_digit($value)) {
                $limits[$key] = $key === 'mountlimit' ? max(1, (int) $value) : (int) $value;
            }
        }

        return $limits;
    }

    /**
     * Another service here has this name, or keeps it for a create not answered yet (code review 2 of #329: two services
     * of one client never end up on one account).
     */
    private static function claimedElsewhere(Service $service, string $name): bool
    {
        return self::claimants($service, $name, [self::USERNAME, self::PENDING]) !== [];
    }

    /**
     * The other services on this Centova with this name, as their username or one of these properties: on it by their own
     * server or, with none, by their product's (as Service::provisioningServer(), R2-4 of #329); names compared as
     * username() reads them, trimmed of any whitespace and in any case (ADV-4, R2-3).
     *
     * @param  list<string>  $keys
     * @return list<Service>
     */
    private static function claimants(Service $service, string $name, array $keys): array
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

    /**
     * Centova has this account with this email (its `listaccounts` row, else its `getaccount`: listaccounts' other fields
     * are not promised, code review 4 of #329).
     */
    private static function hasEmail(CentovaApi $api, string $username, array $row, string $email): bool
    {
        if ($email === '') {
            return false;
        }
        $found = $row['email'] ?? null;
        if (!is_string($found) || $found === '') {
            $account = $api->server($username, 'getaccount')['account'] ?? null;
            $found = is_array($account) && ($account['username'] ?? null) === $username ? ($account['email'] ?? '') : '';
        }

        return is_string($found) && strcasecmp(trim($found), $email) === 0;
    }

    private static function on(mixed $value, bool $default): bool
    {
        return $value === null || $value === '' ? $default : in_array($value, [true, 1, '1', 'on', 'true', 'yes'], true);
    }

    /**
     * A free username from the account's company, its name or its email (as Centova's WHMCS module: lower-case letters,
     * then two digits when taken), free both at Centova and among the services here.
     */
    private static function freeUsername(CentovaApi $api, Service $service): string
    {
        $client = $service->client;
        $stub = '';
        foreach ([$client?->primaryContact->company_name ?? null, $client?->last_name, Str::before((string) $client?->email, '@')] as $source) {
            $stub = substr(preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii((string) $source))), 0, 10);
            if (strlen($stub) >= 3 && ctype_alpha($stub[0])) {
                break;
            }
        }
        if (strlen($stub) < 3 || !ctype_alpha($stub[0])) {
            $stub = 'radio';
        }
        $free = fn (string $name) => !self::claimedElsewhere($service, $name) && self::account($api, $name) === [];
        foreach (array_merge([$stub], array_map(fn (int $i) => $stub . sprintf('%02d', $i), range(1, 30))) as $candidate) {
            if ($free($candidate)) {
                return $candidate;
            }
        }

        throw new Exception("Centova Cast: no free username found from «{$stub}»: create the account by hand");
    }

    /**
     * An account from the product's template. Idempotent and never someone else's: the name is kept before it is asked
     * for; a retry after a lost answer adopts the account only when Centova has it with this account's email (a name
     * taken since by another is given up for a new one); Centova's refusal takes the kept name off.
     */
    public function createServer(Service $service, $settings, $properties)
    {
        $template = trim((string) ($settings['template'] ?? ''));
        if ($template === '') {
            throw new Exception('Centova Cast: the product has no account template');
        }
        $api = $this->api(90);
        if (self::username($service, $properties) !== null) {
            throw new Exception('Centova Cast: the service already has an account');
        }
        $email = (string) $service->client?->email;
        $username = (string) $service->properties()->where('key', self::PENDING)->value('value');
        if ($username !== '') {
            $existing = self::account($api, $username);
            if ($existing !== [] && !self::claimedElsewhere($service, $username) && self::hasEmail($api, $username, $existing, $email)) {
                return $this->adopted($api, $service, $username, $existing);
            }
            if ($existing !== [] || self::claimedElsewhere($service, $username)) {
                $username = ''; // taken since, another service's, or not ours to tell: never adopted
            }
        }
        if ($username === '') {
            $username = self::freeUsername($api, $service);
            $service->properties()->updateOrCreate(['key' => self::PENDING], ['name' => 'Centova account (being created)', 'value' => $username]);
        }
        // Kept (encrypted) before it is asked for: a lost answer still knows the password it was made with.
        $password = Str::password(16, symbols: false);
        $service->forceFill(['password' => $password])->save();
        $client = $service->client;
        $title = trim((string) ($client?->primaryContact?->company_name ?: $client?->last_name ?: $username));
        try {
            $data = $api->system('provision', [
                'template' => $template,
                'username' => $username,
                'adminpassword' => $password,
                'sourcepassword' => Str::password(12, symbols: false),
                'email' => $email,
                'organization' => (string) ($client?->primaryContact->company_name ?? ''),
                'title' => "{$title} Stream",
                'hostname' => 'auto',
                'ipaddress' => 'auto',
                'port' => 'auto',
                'autorebuildlist' => 1,
                'introfile' => '',
                'fallbackfile' => '',
                'autostart' => self::on($settings['autostart'] ?? null, true) ? 1 : 0,
            ] + self::limits($settings));
        } catch (ServerRefused $e) {
            // Refused, yet made (an error after the account was created, code review 3 of #329): kept, never a second one;
            // never one another service here has (R2-1).
            $existing = self::account($api, $username);
            if ($existing !== [] && !self::claimedElsewhere($service, $username) && self::hasEmail($api, $username, $existing, $email)) {
                return $this->adopted($api, $service, $username, $existing);
            }
            $service->properties()->where('key', self::PENDING)->delete();

            throw $e;
        }

        return $this->made($service, $username, is_array($data['account'] ?? null) ? $data['account'] : []);
    }

    /**
     * An account made by an earlier attempt of this create, adopted: its password set to the one kept now, since that
     * attempt's may have been another (a lost answer, then a new password: R2-2 of #329).
     */
    private function adopted(CentovaApi $api, Service $service, string $username, array $account): bool
    {
        $password = (string) $service->password;
        if ($password !== '') {
            $api->server($username, 'reconfigure', ['adminpassword' => $password]);
        }

        return $this->made($service, $username, $account);
    }

    /** The account kept on the service: its name and where it streams (shown to the customer). */
    private function made(Service $service, string $username, array $account): bool
    {
        foreach (['port' => 'Centova port', 'servertype' => 'Centova server type', 'sourcetype' => 'Centova AutoDJ type'] as $key => $name) {
            $value = trim((string) (is_scalar($account[$key] ?? null) ? $account[$key] : ''));
            if ($value !== '') {
                $service->properties()->updateOrCreate(['key' => "centova_{$key}"], ['name' => $name, 'value' => $value]);
            }
        }
        $service->properties()->updateOrCreate(['key' => 'centova_username'], ['name' => 'Centova account', 'value' => $username]);
        $service->properties()->where('key', self::PENDING)->delete();
        $service->forceFill(['username' => $username])->save();

        return true;
    }

    /**
     * Suspend disables the account (Centova stops its stream and its login), only an enabled one: an account disabled by
     * hand is left as it is, and unsuspend will not enable it. Whether its stream was up is kept, so unsuspend restarts it.
     */
    public function suspendServer(Service $service, $settings, $properties)
    {
        $api = $this->api();
        $username = self::owned($api, $service, $properties);
        // One row, as Centova documents it, or its fields alone; not read: nothing assumed (code review 1 of #329).
        $answer = $api->server($username, 'enabled');
        $enabled = $answer['enabled'] ?? (CentovaApi::rows($answer)[0]['enabled'] ?? null);
        // Centova 3.3 answers each value in a `<field>` (`<enabled><field>1</field></enabled>`, live on stream 2026-10-22).
        if (is_array($enabled) && array_key_exists('field', $enabled)) {
            $enabled = $enabled['field'];
        }
        if (!is_scalar($enabled) || !in_array((string) $enabled, ['0', '1'], true)) {
            throw new Exception("Centova Cast: whether «{$username}» is enabled could not be read");
        }
        if ((string) $enabled !== '1') {
            return true;
        }
        $up = collect(CentovaApi::rows($api->system('info')))->firstWhere('username', $username)['expected'] ?? 'down';
        // Marked before it is asked (a lost answer keeps it); Centova's refusal takes it off.
        $service->properties()->updateOrCreate(['key' => self::DISABLED], ['name' => 'Centova account disabled by suspend', 'value' => $up === 'up' ? 'up' : 'down']);
        try {
            $api->system('setstatus', ['username' => $username, 'status' => 'disabled']);
        } catch (ServerRefused $e) {
            $service->properties()->where('key', self::DISABLED)->delete();

            throw $e;
        }

        return true;
    }

    /** Enabled only when this service's suspend disabled it; its stream started again when it was up then. */
    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $mark = $properties[self::DISABLED] ?? null;
        if ($mark === null) {
            return true;
        }
        $api = $this->api();
        $username = self::owned($api, $service, $properties);
        $api->system('setstatus', ['username' => $username, 'status' => 'enabled']);
        $service->properties()->where('key', self::DISABLED)->delete();
        if ($mark === 'up') {
            try {
                $api->server($username, 'start');
            } catch (Exception $e) {
                // Enabled: the customer starts it from the panel; staff see why it did not start.
                report($e);
            }
        }

        return true;
    }

    /**
     * disable_only (default): the account disabled, its media and settings kept. delete (the product's explicit choice):
     * Centova deletes the account, its media and logs.
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        $api = $this->api(120);
        $username = self::owned($api, $service, $properties);
        if (($settings['terminate_mode'] ?? self::DISABLE_ONLY) !== self::DELETE) {
            $api->system('setstatus', ['username' => $username, 'status' => 'disabled']);
            $service->properties()->where('key', self::DISABLED)->delete();

            return true;
        }
        $api->system('terminate', ['username' => $username]);
        $service->properties()->whereIn('key', ['centova_username', 'centova_port', 'centova_servertype', 'centova_sourcetype', self::DISABLED])->delete();
        $service->forceFill(['username' => null])->save();

        return true;
    }

    /**
     * A product change: the new product's limits set on the account. Centova applies a template only when an account is
     * made, so a product whose limits are all its template's has nothing to send: told, never a silent success.
     */
    public function upgradeServer(Service $service, $settings, $properties)
    {
        $limits = self::limits($settings);
        if ($limits === []) {
            throw new Exception('Centova Cast: the new product sets no limits of its own (only its template, which Centova applies on create): set its limits, or change the account in Centova');
        }
        $api = $this->api();
        $api->server(self::owned($api, $service, $properties), 'reconfigure', $limits);

        return true;
    }

    /**
     * A new password for the account's panel: `reconfigure` with it alone (Centova takes a partial reconfigure, as its own
     * module's plan change sends; the account is never read and sent back, code review 5 of #329). Kept once Centova took it.
     */
    public function changePassword(Service $service, $settings, $properties, string $password, bool $mysqlToo = false): bool
    {
        $api = $this->api();
        $username = self::owned($api, $service, $properties);
        $api->server($username, 'reconfigure', ['adminpassword' => $password]);
        $service->forceFill(['password' => $password])->save();

        return true;
    }

    /** The panel's sign-in page (Centova signs in by a posted form: the customer types the account's password there). */
    public function getLoginUrl(Service $service, $settings, $properties): string
    {
        return $this->api()->baseUrl() . '/login/index.php';
    }

    public function getActions(Service $service, $settings, $properties): array
    {
        $username = self::username($service, $properties);
        if ($username === null) {
            return [];
        }
        $host = (string) parse_url((string) $this->config('host'), PHP_URL_HOST);
        $fields = [['label' => __('services.centova.username'), 'type' => 'text', 'text' => $username]];
        if ($host !== '' && filled($properties['centova_port'] ?? null)) {
            $fields[] = ['label' => __('services.centova.server'), 'type' => 'text', 'text' => "{$host}:{$properties['centova_port']}"];
        }
        if (filled($properties['centova_servertype'] ?? null)) {
            $fields[] = ['label' => __('services.centova.servertype'), 'type' => 'text', 'text' => (string) $properties['centova_servertype']];
        }

        return array_merge([
            ['label' => __('services.centova.login'), 'type' => 'button', 'sso' => true, 'function' => 'getLoginUrl'],
            ['type' => 'password', 'function' => 'changePassword'],
        ], $fields);
    }

    /**
     * Usage of every account here, from one `usage` call (Centova's figures for the month, in MB; -1: no limit); a service
     * whose account Centova does not have is left out.
     *
     * @param  list<Service>  $services
     * @return array<int, ServiceUsageRead>
     */
    public function usage(array $services): array
    {
        $rows = collect(CentovaApi::rows($this->api(60)->system('usage')))->keyBy(fn (array $row) => (string) ($row['username'] ?? ''));
        $limit = fn (mixed $value) => is_numeric($value) && (int) $value > 0 ? (int) $value : null;
        $read = [];
        foreach ($services as $service) {
            $username = self::username($service, $service->properties->pluck('value', 'key')->all());
            $row = $username !== null ? $rows->get($username) : null;
            if ($row === null) {
                continue;
            }
            $read[$service->id] = new ServiceUsageRead(
                diskUsedMb: is_numeric($row['diskusage'] ?? null) ? (int) $row['diskusage'] : null,
                diskLimitMb: $limit($row['diskquota'] ?? null),
                bandwidthUsedMb: is_numeric($row['transferusage'] ?? null) ? (int) $row['transferusage'] : null,
                bandwidthLimitMb: $limit($row['transferlimit'] ?? null),
            );
        }

        return $read;
    }
}
