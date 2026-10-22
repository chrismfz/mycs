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

    private function client(int $timeout = 30): MailcowClient
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
     * A domain and its domain admin (as the WHMCS module: domain, DKIM, admin). Idempotent: the admin's name is kept
     * before it is asked for, so a retry after a lost answer adopts what was made, never a second admin; a domain already
     * at mailcow is adopted only when this service's admin has it (else it is someone else's: refused).
     */
    public function createServer(Service $service, $settings, $properties)
    {
        $client = $this->client();
        $domain = self::domainOf($service, $properties);
        $username = self::username($service, $properties) ?? (string) $service->properties()->where('key', 'mailcow_pending_username')->value('value');
        if ($username === '') {
            $username = Credentials::username($domain, fn (string $candidate) => $client->getDomainAdmin($candidate) === []);
            $service->properties()->updateOrCreate(['key' => 'mailcow_pending_username'], ['name' => 'mailcow admin (being created)', 'value' => $username]);
        }
        $admin = $client->getDomainAdmin($username);
        if ($client->getDomain($domain) === []) {
            $client->addDomain($domain, DomainLimits::fromMeta($settings), self::on($settings['restart_sogo'] ?? null, true));
        } elseif (!in_array($domain, MailcowClient::parseDomainList($admin['selected_domains'] ?? ''), true)) {
            throw new Exception("mailcow: {$domain} is already on this server under another admin: check it there");
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
        $service->properties()->where('key', 'mailcow_pending_username')->delete();
        $service->forceFill(['username' => $username, 'domain' => $service->domain ?? $domain])->save();

        return true;
    }

    /** Suspend locks the domain admin only (the mailboxes keep working), when the product says so (Policy). */
    public function suspendServer(Service $service, $settings, $properties)
    {
        $username = self::username($service, $properties) ?? throw new Exception('Service has not been created');
        $client = $this->client();
        $domains = $client->getDomainAdminDomains($username);
        if (self::policy($settings)->lockOnSuspend($domains)) {
            $client->setDomainAdminActive($domains, $username, false);
        }

        return true;
    }

    /** Unlocked only where suspend would have locked it: never an admin locked by hand or for another service. */
    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $username = self::username($service, $properties) ?? throw new Exception('Service has not been created');
        $client = $this->client();
        $domains = $client->getDomainAdminDomains($username);
        if (self::policy($settings)->unlockOnUnsuspend($domains)) {
            $client->setDomainAdminActive($domains, $username, true);
        }

        return true;
    }

    /**
     * lock_only (default): the admin locked, the mail kept. delete_all (the product's explicit choice): mailboxes,
     * aliases, the admin (only when it has no other domain, else this domain is taken off its list) and the domain; each
     * step tried, what failed told.
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        $username = self::username($service, $properties) ?? throw new Exception('Service has not been created');
        $client = $this->client(120);
        $domain = self::domainOf($service, $properties);
        $domains = $client->getDomainAdminDomains($username);
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
        $service->properties()->where('key', 'mailcow_username')->delete();
        $service->forceFill(['username' => null])->save();

        return true;
    }

    /** A product change: its new limits on the domain. */
    public function upgradeServer(Service $service, $settings, $properties)
    {
        self::username($service, $properties) ?? throw new Exception('Service has not been created');
        $this->client()->editDomain(self::domainOf($service, $properties), DomainLimits::fromMeta($settings));

        return true;
    }

    /** A new password for the domain admin (the customer's and staff's «Change password»); kept once mailcow took it. */
    public function changePassword(Service $service, $settings, $properties, string $password, bool $mysqlToo = false): bool
    {
        $username = self::username($service, $properties) ?? throw new Exception('Service has not been created');
        $client = $this->client();
        self::answered(fn () => $client->changeDomainAdminPassword($client->getDomainAdminDomains($username), $username, $password));
        $service->forceFill(['password' => $password])->save();

        return true;
    }

    /** Into the domain admin, signed in (mailcow's SSO token), as WHMCS's «Login to Mailcow». */
    public function getLoginUrl(Service $service, $settings, $properties): string
    {
        $username = self::username($service, $properties) ?? throw new Exception('Service has not been created');
        $client = $this->client();

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
     * Usage: the domains its admin manages, summed (mailcow's own figures: bytes used, the domain quota), with how many
     * mailboxes it has and may have. Per service (mailcow has no server-wide call for a domain admin's domains).
     *
     * @param  list<Service>  $services
     * @return array<int, ServiceUsageRead>
     */
    public function usage(array $services): array
    {
        $client = $this->client(60);
        $read = [];
        foreach ($services as $service) {
            $properties = $service->properties->pluck('value', 'key')->all();
            $username = self::username($service, $properties);
            $domains = $username !== null ? $client->getDomainAdminDomains($username) : [];
            if ($domains === []) {
                continue;
            }
            $totals = Usage::totals($client->overview($domains));
            $limits = DomainLimits::fromMeta($service->product?->settings?->pluck('value', 'key')->all() ?? []);
            $read[$service->id] = new ServiceUsageRead(
                diskUsedMb: (int) round($totals['used'] / 1048576),
                diskLimitMb: $totals['quota'] > 0 ? (int) round($totals['quota'] / 1048576) : null,
                accounts: $totals['mailboxes'],
                accountsLimit: $limits->mailboxes > 0 ? $limits->mailboxes * count($domains) : null,
                accountsKind: 'mailboxes',
            );
        }

        return $read;
    }

    /**
     * @param  list<Service>  $services
     * @return array<int, list<array{domain: string, type: string, account: string}>>
     */
    public function hostedDomains(array $services): array
    {
        $client = $this->client(60);
        $admins = $client->getDomainAdmins();
        $read = [];
        foreach ($services as $service) {
            $properties = $service->properties->pluck('value', 'key')->all();
            $username = self::username($service, $properties);
            $record = $username !== null ? $client->getDomainAdmin($username, $admins) : [];
            $domains = $record !== [] ? MailcowClient::parseDomainList($record['selected_domains'] ?? '') : [];
            if ($domains !== []) {
                $read[$service->id] = array_map(fn (string $domain) => ['domain' => $domain, 'type' => 'main', 'account' => (string) $username], $domains);
            }
        }

        return $read;
    }
}
