<?php

namespace MyCS\Extensions\Servers\Mailcow;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Contracts\ReadsHostedDomains;
use App\Contracts\ReadsServiceUsage;
use App\Exceptions\ServerRefused;
use App\Models\Service;
use App\Rules\Domain;
use App\Support\Services\ServiceUsageRead;
use Exception;
use Nixpal\Mailcow\Credentials;
use Nixpal\Mailcow\DomainLimits;
use Nixpal\Mailcow\MailcowClient;
use Nixpal\Mailcow\MailcowException;
use Nixpal\Mailcow\Policy;
use Nixpal\Mailcow\Sso;
use Nixpal\Mailcow\Usage;

/**
 * mailcow hosted email (owner 2026-10-22: myip's 10 «MyMail» services on «Mymail Node 1»), on packages/mailcow, the port
 * of our own WHMCS module: a domain and its domain admin per service, DKIM, suspend/terminate by the product's policy
 * (lock_only by default: the admin locked, the mail kept; delete_all only when the product says so), SSO into the domain
 * admin, its password, usage and the domains its admin manages.
 */
#[ExtensionMeta(
    name: 'mailcow',
    description: 'mailcow hosted email: a domain and its domain admin per service',
    version: 'builtin',
    author: 'MyCS',
)]
class Mailcow extends Server implements ReadsHostedDomains, ReadsServiceUsage
{
    public function getConfig($values = []): array
    {
        return [
            ['name' => 'host', 'type' => 'text', 'label' => 'Hostname', 'placeholder' => 'https://mail.example.com', 'required' => true],
            ['name' => 'apikey', 'type' => 'password', 'label' => 'API key (read-write)', 'required' => true, 'encrypted' => true],
            ['name' => 'sso_endpoint', 'type' => 'text', 'label' => 'SSO endpoint', 'default' => MailcowClient::DEFAULT_SSO_ENDPOINT],
            ['name' => 'sso_path', 'type' => 'text', 'label' => 'SSO redirect path', 'default' => '/domainadmin/',
                'description' => 'Where the customer lands after signing in: /domainadmin/, / or /SOGo/.'],
        ];
    }

    /** As the WHMCS module's options (its products' values: MyMail 25 … 1000). */
    public function getProductConfig($values = []): array
    {
        return [
            ['name' => 'aliases', 'type' => 'number', 'label' => 'Max aliases', 'default' => 100],
            ['name' => 'mailboxes', 'type' => 'number', 'label' => 'Max mailboxes', 'default' => 10],
            ['name' => 'defquota', 'type' => 'number', 'label' => 'Default mailbox quota (MB)', 'default' => 1024],
            ['name' => 'maxquota', 'type' => 'number', 'label' => 'Max mailbox quota (MB)', 'default' => 1024],
            ['name' => 'quota', 'type' => 'number', 'label' => 'Domain quota (MB)', 'default' => 10240],
            ['name' => 'rl_value', 'type' => 'number', 'label' => 'Rate limit', 'default' => 10],
            ['name' => 'rl_frame', 'type' => 'select', 'label' => 'Rate limit per', 'default' => 's',
                'options' => [['value' => 's', 'label' => 'second'], ['value' => 'm', 'label' => 'minute'], ['value' => 'h', 'label' => 'hour'], ['value' => 'd', 'label' => 'day']]],
            ['name' => 'create_dkim', 'type' => 'checkbox', 'label' => 'Create DKIM', 'default' => true],
            ['name' => 'dkim_selector', 'type' => 'text', 'label' => 'DKIM selector', 'default' => 'dkim'],
            ['name' => 'dkim_keysize', 'type' => 'select', 'label' => 'DKIM key size', 'default' => '2048',
                'options' => [['value' => '1024', 'label' => '1024'], ['value' => '2048', 'label' => '2048'], ['value' => '4096', 'label' => '4096']]],
            ['name' => 'restart_sogo', 'type' => 'checkbox', 'label' => 'Restart SOGo after adding the domain', 'default' => true],
            ['name' => 'terminate_mode', 'type' => 'select', 'label' => 'On terminate', 'default' => Policy::LOCK_ONLY,
                'options' => [['value' => Policy::LOCK_ONLY, 'label' => 'Lock the domain admin only (safe: the mail is kept)'],
                    ['value' => Policy::DELETE_ALL, 'label' => 'Delete mailboxes, aliases and the domain (irreversible)']]],
            ['name' => 'lock_on_suspend', 'type' => 'checkbox', 'label' => 'Lock the domain admin on suspend (mailboxes keep working)', 'default' => true],
            ['name' => 'force_lock_multi', 'type' => 'checkbox', 'label' => 'Lock even an admin of several domains (locks all of them)', 'default' => false],
        ];
    }

    public function getCheckoutConfig()
    {
        return [['name' => 'domain', 'type' => 'text', 'label' => 'Domain', 'required' => true, 'validation' => [new Domain, 'required'], 'placeholder' => 'domain.com']];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->client()->testConnection();
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    public function client(int $timeout = 30): MailcowClient
    {
        return new MailcowClient(new LaravelTransport($timeout), (string) $this->config('host'), trim((string) $this->config('apikey')),
            $this->config('sso_endpoint') ?: null);
    }

    /**
     * mailcow's own refusal («mailcow error for …», its reason) as a ServerRefused the customer may read; no answer
     * («mailcow HTTP …», a timeout) stays as it is: its outcome is not known.
     */
    private static function answered(callable $call): mixed
    {
        try {
            return $call();
        } catch (MailcowException $e) {
            if (str_starts_with($e->getMessage(), 'mailcow error for ')) {
                throw new ServerRefused(preg_replace('#^mailcow error for /api/v1/[^:]+: #', 'mailcow: ', $e->getMessage()), 0, $e);
            }

            throw $e;
        }
    }

    /**
     * A call whose marker was set before it was asked: mailcow's own refusal («nothing was done») takes the marker off,
     * so it never vouches later for what did not happen (R2-1, R2-2 of #324); no answer keeps it (it may have been done).
     */
    private static function refusedClears(Service $service, string $marker, callable $call): mixed
    {
        try {
            return $call();
        } catch (MailcowException $e) {
            if (str_starts_with($e->getMessage(), 'mailcow error for ')) {
                $service->properties()->where('key', $marker)->delete();
            }

            throw $e;
        }
    }

    /** Some domain admin manages this domain at mailcow now. */
    private static function managedByAnyone(MailcowClient $client, string $domain): bool
    {
        foreach ($client->getDomainAdmins() as $admin) {
            if (in_array($domain, MailcowClient::parseDomainList($admin['selected_domains'] ?? ''), true)) {
                return true;
            }
        }

        return false;
    }

    private static function on(mixed $value, bool $default): bool
    {
        return $value === null || $value === '' ? $default : in_array($value, [true, 1, '1', 'on', 'true', 'yes'], true);
    }

    private static function policy(array $settings): Policy
    {
        return Policy::fromMeta([
            'lock_on_suspend' => self::on($settings['lock_on_suspend'] ?? null, true) ? 'true' : 'false',
            'force_lock_multi' => self::on($settings['force_lock_multi'] ?? null, false) ? 'true' : 'false',
            'terminate_mode' => (string) ($settings['terminate_mode'] ?? Policy::LOCK_ONLY),
        ]);
    }

    private static function domainOf(Service $service, array $properties): string
    {
        $domain = strtolower(trim((string) ($properties['domain'] ?? $service->domain ?? '')));
        if ($domain === '') {
            throw new Exception('mailcow: the service has no domain');
        }

        return $domain;
    }

    /** The domain admin's username: kept on the service (an imported one on services.username, as WHMCS). */
    private static function username(Service $service, array $properties): ?string
    {
        $username = trim((string) ($properties['mailcow_username'] ?? $service->username ?? ''));

        return $username !== '' ? $username : null;
    }

    /**
     * The service's own domain admin, as mailcow has it: it exists (its exact name) and manages this service's domain.
     * Every action on an admin goes through here: never one the service does not own (an imported or typed name that is
     * another customer's, a name not found), never an empty domain list sent (adversarial ADV-3…9 of #324).
     *
     * @param  list<array<string, mixed>>|null  $admins  mailcow's admins, read once for many services
     * @return array{0: string, 1: list<string>, 2: array<string, mixed>} its name, its domains, its record
     */
    private static function ownedAdmin(MailcowClient $client, Service $service, array $properties, ?array $admins = null): array
    {
        $username = self::username($service, $properties) ?? throw new Exception('Service has not been created');
        $domain = self::domainOf($service, $properties);
        $record = $client->getDomainAdmin($username, $admins);
        if ($record === []) {
            throw new Exception("mailcow: no domain admin «{$username}» on this server: check the service's username");
        }
        $domains = MailcowClient::parseDomainList($record['selected_domains'] ?? '');
        if (!in_array($domain, $domains, true)) {
            throw new Exception("mailcow: the domain admin «{$username}» does not manage {$domain}: check the service before anything is done");
        }

        return [$username, $domains, $record];
    }

    /**
     * A domain and its domain admin (as the WHMCS module: domain, DKIM, admin). Idempotent and never someone else's:
     * - the admin's name is kept before it is asked for; an existing admin is adopted only when it already manages this
     *   domain, and a name taken since (by another service) is given up for a new one (ADV-1, ADV-2 of #324);
     * - a domain already at mailcow is adopted only when this service's admin has it, or when this service added it
     *   (marked before it was asked for: a lost answer, ADV-11 / code review 1); anyone else's is refused.
     */
    public function createServer(Service $service, $settings, $properties)
    {
        $client = $this->client(90);
        $domain = self::domainOf($service, $properties);
        $username = self::username($service, $properties) ?? (string) $service->properties()->where('key', 'mailcow_pending_username')->value('value');
        $admin = $username !== '' ? $client->getDomainAdmin($username) : [];
        if ($admin !== [] && !in_array($domain, MailcowClient::parseDomainList($admin['selected_domains'] ?? ''), true)) {
            $username = ''; // someone else's admin now: never adopted
            $admin = [];
        }
        if ($username === '') {
            $free = fn (string $candidate) => $client->getDomainAdmin($candidate) === [];
            $username = Credentials::username($domain, $free);
            if (!$free($username)) {
                throw new Exception("mailcow: no free domain admin name found for {$domain}: create it by hand");
            }
            $service->properties()->updateOrCreate(['key' => 'mailcow_pending_username'], ['name' => 'mailcow admin (being created)', 'value' => $username]);
        }
        $ours = $service->properties()->where('key', 'mailcow_domain_added')->value('value') === $domain;
        if ($client->getDomain($domain) === []) {
            $service->properties()->updateOrCreate(['key' => 'mailcow_domain_added'], ['name' => 'mailcow domain added by this service', 'value' => $domain]);
            self::refusedClears($service, 'mailcow_domain_added',
                fn () => $client->addDomain($domain, DomainLimits::fromMeta($settings), self::on($settings['restart_sogo'] ?? null, true)));
        } elseif ($admin === [] && (!$ours || self::managedByAnyone($client, $domain))) {
            // Not ours, or ours once but another admin manages it now (set up since: R2-1 of #324): never adopted.
            throw new Exception("mailcow: {$domain} is already on this server, not made by this service: check it there");
        }
        if (self::on($settings['create_dkim'] ?? null, true)) {
            $client->ensureDkim($domain, (string) ($settings['dkim_selector'] ?? 'dkim') ?: 'dkim', (int) ($settings['dkim_keysize'] ?? 2048) ?: 2048);
        }
        if ($admin === []) {
            // Kept (encrypted) before it is asked for: a lost answer still knows the password it was made with.
            $password = Credentials::password();
            $service->forceFill(['password' => $password])->save();
            $client->addDomainAdmin($domain, $username, $password);
        }
        $service->properties()->updateOrCreate(['key' => 'mailcow_username'], ['name' => 'mailcow domain admin', 'value' => $username]);
        $service->properties()->whereIn('key', ['mailcow_pending_username', 'mailcow_domain_added'])->delete();
        $service->forceFill(['username' => $username, 'domain' => $service->domain ?? $domain])->save();

        return true;
    }

    /** The service's mark that it locked its admin (suspend), so unsuspend unlocks only what it locked (ADV-10 of #324). */
    public const LOCKED = 'mailcow_locked_by_us';

    /**
     * Suspend locks the domain admin only (the mailboxes keep working), when the product says so (Policy), and only an
     * active one: an admin already locked (by hand, for abuse) is left as it is, and unsuspend will not unlock it.
     */
    public function suspendServer(Service $service, $settings, $properties)
    {
        $client = $this->client();
        [$username, $domains, $record] = self::ownedAdmin($client, $service, $properties);
        if (self::policy($settings)->lockOnSuspend($domains) && in_array($record['active'] ?? 1, [1, '1', true], true)) {
            // Marked before it is asked (a lost answer keeps it); a refusal takes it off (R2-2 of #324).
            $service->properties()->updateOrCreate(['key' => self::LOCKED], ['name' => 'mailcow admin locked by suspend', 'value' => '1']);
            self::refusedClears($service, self::LOCKED, fn () => $client->setDomainAdminActive($domains, $username, false));
        }

        return true;
    }

    /** Unlocked only when this service's suspend locked it: never an admin locked by hand or for another service. */
    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $client = $this->client();
        [$username, $domains] = self::ownedAdmin($client, $service, $properties);
        if (($properties[self::LOCKED] ?? null) === '1') {
            $client->setDomainAdminActive($domains, $username, true);
            $service->properties()->where('key', self::LOCKED)->delete();
        }

        return true;
    }

    /**
     * lock_only (default): the admin locked, the mail kept. delete_all (the product's explicit choice), only on the
     * service's own admin and domain (code review 2): mailboxes, aliases, the admin (only when it has no other domain,
     * else this domain is taken off its list) and the domain; each step tried, what failed told.
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        $client = $this->client(120);
        $domain = self::domainOf($service, $properties);
        [$username, $domains] = self::ownedAdmin($client, $service, $properties);
        $policy = self::policy($settings);
        if (!$policy->deletesOnTerminate()) {
            if ($policy->lockOnTerminate($domains)) {
                $client->setDomainAdminActive($domains, $username, false);
            }

            return true;
        }
        $errors = [];
        foreach ([
            'mailboxes' => fn () => $client->removeDomainMailboxes($domain),
            'aliases' => fn () => $client->removeDomainAliases($domain),
            'domain admin' => fn () => Policy::adminIsOnlyFor($domain, $domains)
                ? $client->removeDomainAdmin($username)
                : $client->setDomainAdminDomains($username, array_values(array_diff($domains, [$domain]))),
            'domain' => fn () => $client->removeDomain($domain),
        ] as $step => $run) {
            try {
                $run();
            } catch (Exception $e) {
                $errors[] = "{$step}: {$e->getMessage()}";
            }
        }
        if ($errors !== []) {
            throw new Exception('mailcow delete_all incomplete: ' . implode('; ', $errors));
        }
        $service->properties()->whereIn('key', ['mailcow_username', self::LOCKED])->delete();
        $service->forceFill(['username' => null])->save();

        return true;
    }

    /** A product change: its new limits on its own domain. */
    public function upgradeServer(Service $service, $settings, $properties)
    {
        $client = $this->client();
        self::ownedAdmin($client, $service, $properties);
        $client->editDomain(self::domainOf($service, $properties), DomainLimits::fromMeta($settings));

        return true;
    }

    /** A new password for its own domain admin (the customer's and staff's «Change password»); kept once mailcow took it. */
    public function changePassword(Service $service, $settings, $properties, string $password, bool $mysqlToo = false): bool
    {
        $client = $this->client();
        [$username, $domains] = self::ownedAdmin($client, $service, $properties);
        self::answered(fn () => $client->changeDomainAdminPassword($domains, $username, $password));
        $service->forceFill(['password' => $password])->save();

        return true;
    }

    /** Into its own domain admin, signed in (mailcow's SSO token), as WHMCS's «Login to Mailcow». */
    public function getLoginUrl(Service $service, $settings, $properties): string
    {
        $client = $this->client();
        [$username] = self::ownedAdmin($client, $service, $properties);

        return Sso::loginUrl($client->getBaseUrl(), (string) ($this->config('sso_path') ?: '/domainadmin/'), $client->issueSsoToken($username));
    }

    public function getActions(Service $service, $settings, $properties): array
    {
        if (self::username($service, $properties) === null) {
            return [];
        }

        return [
            ['label' => __('services.mailcow.login'), 'type' => 'button', 'sso' => true, 'function' => 'getLoginUrl'],
            ['type' => 'password', 'function' => 'changePassword'],
        ];
    }

    /**
     * Usage of its own domain (mailcow's figures for it: bytes used, its quota, its mailboxes and their limit): the admins
     * read once, then one get/domain a service (code review 3, 5 of #324); a service whose admin it does not own is left
     * out (ADV-7).
     *
     * @param  list<Service>  $services
     * @return array<int, ServiceUsageRead>
     */
    public function usage(array $services): array
    {
        $client = $this->client(60);
        $admins = $client->getDomainAdmins();
        $read = [];
        foreach ($services as $service) {
            $properties = $service->properties->pluck('value', 'key')->all();
            try {
                self::ownedAdmin($client, $service, $properties, $admins);
            } catch (Exception) {
                continue;
            }
            $domain = $client->getDomain(self::domainOf($service, $properties));
            if ($domain === []) {
                continue;
            }
            $quota = Usage::bytes($domain['max_quota_for_domain'] ?? 0);
            $limit = (int) ($domain['max_num_mboxes_for_domain'] ?? 0);
            $read[$service->id] = new ServiceUsageRead(
                diskUsedMb: (int) round(Usage::bytes($domain['bytes_total'] ?? 0) / 1048576),
                diskLimitMb: $quota > 0 ? (int) round($quota / 1048576) : null,
                accounts: isset($domain['mboxes_in_domain']) ? (int) $domain['mboxes_in_domain'] : null,
                accountsLimit: $limit > 0 ? $limit : null,
                accountsKind: 'mailboxes',
            );
        }

        return $read;
    }

    /**
     * The domains its own admin manages (ADV-7 of #324: never an admin it does not own).
     *
     * @param  list<Service>  $services
     * @return array<int, list<array{domain: string, type: string, account: string}>>
     */
    public function hostedDomains(array $services): array
    {
        $client = $this->client(60);
        $admins = $client->getDomainAdmins();
        $read = [];
        foreach ($services as $service) {
            try {
                [$username, $domains] = self::ownedAdmin($client, $service, $service->properties->pluck('value', 'key')->all(), $admins);
            } catch (Exception) {
                continue;
            }
            $read[$service->id] = array_map(fn (string $domain) => ['domain' => $domain, 'type' => 'main', 'account' => $username], $domains);
        }

        return $read;
    }
}
