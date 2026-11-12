<?php

namespace MyCS\Extensions\Servers\DirectAdmin;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Service;
use App\Rules\Domain;
use App\Support\PanelConnection;
use App\Support\Services\SharedAccount;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;
use Throwable;

#[ExtensionMeta(
    name: 'DirectAdmin',
    description: 'DirectAdmin server extension',
    version: 'builtin',
    author: 'MyCS',
    icon: 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTExIiBoZWlnaHQ9IjExMSIgdmlld2JveD0iMCAwIDExMSAxMTEiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgY2xhc3M9ImhvdmVyOnNjYWxlLTEyMCBmbGV4IGl0ZW1zLWNlbnRlciBqdXN0aWZ5LWNlbnRlciBzaXplLTE4IHJvdW5kZWQteGwgcC00IGJnLVsjMjIyNzJFXSByaW5nLTYgcmluZy1bIzIyMjcyRV0vNDAiPjxwYXRoIGQ9Ik0xNS44MzQ0IDQwLjE3MUwzMS41NDQzIDU1Ljg4MDlMMTUuODM0NCA3MS41OTA4TDI5Ljk3MzMgODUuNzI5N0w2MS4zOTMxIDU0LjMwOTlMMzEuNTQ0MyAyNC40NjExTDE1LjgzNDQgNDAuMTcxWiIgZmlsbD0iIzMxQjdFOSI+PC9wYXRoPjxwYXRoIGQ9Ik01OS44MjI2IDUyLjczODRMMjguNDAyOCA4NC4xNTgyTDMxLjU0NDggODcuMzAwMkw2Mi45NjQ2IDU1Ljg4MDRMNTkuODIyNiA1Mi43Mzg0WiIgZmlsbD0iIzA2ODJCNCI+PC9wYXRoPjxwYXRoIGQ9Ik0zOS4zOTkyIDE2LjYwNjFMNzguNjc0IDU1Ljg4MDlMMzkuMzk5MiA5NS4xNTU2TDUzLjUzODEgMTA5LjI5NEwxMDguNTIzIDU0LjMwOTlMNTUuMTA5MSAwLjg5NjIzNkwzOS4zOTkyIDE2LjYwNjFaIiBmaWxsPSIjMzFCN0U5Ij48L3BhdGg+PHBhdGggZD0iTTEwNi45NTEgNTIuNzM4TDUxLjk2NjggMTA3LjcyM0w1NS4xMDg4IDExMC44NjVMMTEwLjA5MyA1NS44OEwxMDYuOTUxIDUyLjczOFoiIGZpbGw9IiMwNjgyQjQiPjwvcGF0aD48L3N2Zz4=',
)]
class DirectAdmin extends Server
{
    /** The username a create asked for, kept until the account is made (sweep2 F-PROVISIONING-OPS-3). */
    public const PENDING = 'directadmin_pending_username';

    /** DirectAdmin over HTTPS, to its server's IP when set (App\Support\PanelConnection). */
    private function panel(): PendingRequest
    {
        return PanelConnection::http(rtrim(trim((string) $this->config('host')), '/'), $this->config('ip'), (bool) $this->config('self_signed'));
    }

    private function request($endpoint, $method = 'get', $data = [], $parse = false)
    {
        $host = rtrim(trim((string) $this->config('host')), '/');
        $url = $host . $endpoint;

        $response = $this->panel()->withBasicAuth(
            $this->config('username'),
            $this->config('password')
        )->withHeaders([
            'Content-Type' => 'application/json',
        ])->$method($url, $data)->throw();

        if ($parse) {
            return $this->parse($response);
        }

        return $response;
    }

    private function parse($response)
    {
        $body = html_entity_decode($response->body());
        parse_str($body, $parsed);
        if (isset($parsed['list']) && is_array($parsed['list'])) {
            return $parsed['list'];
        }

        return $parsed;
    }

    /**
     * Get all the configuration for the extension
     *
     * @param  array  $values
     */
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'label' => 'DirectAdmin URL',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'https://server.example.com:2222',
                'validation' => 'url:https', // its admin password goes with every request (PROV-3)
            ],
            ...PanelConnection::fields(),
            [
                'name' => 'username',
                'label' => 'DirectAdmin User name',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'password',
                'label' => 'DirectAdmin Password',
                'type' => 'password',
                'required' => true,
                'encrypted' => true,
            ],
        ];
    }

    /**
     * Get product config
     *
     * @param  array  $values
     */
    public function getProductConfig($values = []): array
    {
        $upackages = $this->request('/CMD_API_PACKAGES_USER', parse: true);
        // Map to label => value pairs
        $upackages = array_map(function ($package) {
            return ['label' => $package, 'value' => $package];
        }, $upackages);

        try {
            // If you are a reseller you won't have access to reseller packages
            $rpackages = $this->request('/CMD_API_PACKAGES_RESELLER', parse: true);
            // Merge user packages with reseller packages
            $rpackages = array_map(function ($package) {
                return ['label' => $package . ' (reseller)', 'value' => $package];
            }, $rpackages);
        } catch (Exception $e) {
            $rpackages = [];
        }

        $packages = array_merge($upackages, $rpackages);

        $ips = $this->request('/CMD_API_SHOW_RESELLER_IPS', parse: true);

        return [
            [
                'name' => 'package',
                'type' => 'select',
                'label' => 'Package',
                'required' => true,
                'options' => $packages,
            ],
            [
                'name' => 'ip',
                'type' => 'select',
                'label' => 'IP Address',
                'required' => false,
                'options' => $ips,
            ],
        ];
    }

    public function getCheckoutConfig()
    {
        return [
            [
                'name' => 'domain',
                'type' => 'text',
                'label' => 'Domain',
                'required' => true,
                'validation' => [new Domain, 'required'],
                'placeholder' => 'domain.com',
            ],
        ];
    }

    /**
     * Check if currenct configuration is valid
     */
    public function testConfig(): bool|string
    {
        try {
            $this->request('/CMD_API_SHOW_USERS')->body();

            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Create a server
     *
     * @param  array  $settings  (product settings)
     * @param  array  $properties  (checkout options)
     * @return array<string, mixed>
     */
    public function createServer(Service $service, $settings, $properties)
    {
        // Idempotent (sweep2 F-PROVISIONING-OPS-3, as cPanel's): the username, and the password DirectAdmin mails with
        // notify=yes, are kept before the account is asked for. A retry after a lost answer (a timeout once DirectAdmin
        // made it) asks again for that same account and adopts it when it is there, instead of a second name for the
        // same domain that DirectAdmin refuses for ever. «Pending» until made: suspend/terminate never act on it.
        $pending = $service->properties()->where('key', self::PENDING)->value('value');
        $retry = is_string($pending) && $pending !== '';
        $username = $retry ? $pending : chr(random_int(97, 122)) . Str::lower(Str::random(7)); // a letter first, as DirectAdmin wants
        // A CSPRNG (str_shuffle is not one: sweep §6 #16); the symbols DirectAdmin accepts. Kept in the service's own
        // encrypted field (never a property: sweep §6 #2), so an adopted account keeps the password it was mailed with.
        $kept = $retry ? $service->password : null;
        $password = is_string($kept) && $kept !== '' ? $kept : Str::password(16, symbols: false) . '!';
        $settings = array_merge($settings, $properties);
        $domain = (string) ($properties['domain'] ?? '');

        if (isset($settings['ip'])) {
            $ip = $settings['ip'];
        } else {
            $ip = $this->request('/CMD_API_SHOW_RESELLER_IPS', parse: true)[0] ?? null;

            if (!$ip) {
                throw new Exception('No IP address available for the server');
            }
        }

        $service->properties()->updateOrCreate(['key' => self::PENDING], ['name' => 'DirectAdmin username (being created)', 'value' => $username]);
        $service->forceFill(['password' => $password])->save();

        $response = $this->request('/CMD_API_ACCOUNT_USER', 'post', [
            'action' => 'create',
            'add' => 'Submit',
            'username' => $username,
            'email' => $service->client->email,
            'passwd' => $password,
            'passwd2' => $password,
            'package' => $settings['package'],
            'ip' => $ip,
            'domain' => $domain,
            'notify' => 'yes',
        ], parse: true);

        if (($response['error'] ?? null) !== '0') {
            if ($retry && $this->ownsDomain($username, $domain)) {
                return $this->made($service, $username, $password, $domain, $ip); // made by the attempt whose answer was lost
            }
            $text = $response['text'] ?? 'no answer';
            if (isset($response['error']) && !$retry) {
                // Refused on its first try (not lost): nothing was made under that name, nothing to keep.
                $service->properties()->where('key', self::PENDING)->delete();
                $service->forceFill(['password' => null])->save();

                throw new Exception('Error creating DirectAdmin account: ' . $text);
            }

            // An odd answer, or a retry refused: the account may be on DirectAdmin under that name. Kept, so the next
            // try (or staff, in DirectAdmin) finds it, never a second account under a new name.
            throw new Exception("Error creating DirectAdmin account {$username} (it may exist from an earlier attempt: check DirectAdmin): {$text}");
        }

        return $this->made($service, $username, $password, $domain, $ip);
    }

    /** Whether DirectAdmin has the account $username, for $domain (a lookup that fails: no). */
    private function ownsDomain(string $username, string $domain): bool
    {
        try {
            $config = $this->request('/CMD_API_SHOW_USER_CONFIG', 'get', ['user' => $username], parse: true);
        } catch (Throwable) {
            return false;
        }

        $theirs = self::comparableDomain((string) ($config['domain'] ?? ''));

        return is_array($config) && $theirs !== '' && $theirs === self::comparableDomain($domain);
    }

    /**
     * A domain in the form DirectAdmin keeps it: punycode and lower-case (REV2-FPO-1). An IDN domain (a Greek .gr) is
     * stored in unicode on the service, but DirectAdmin shows it as xn--…, and strtolower() leaves non-ASCII letters as
     * they are.
     */
    private static function comparableDomain(string $domain): string
    {
        $domain = rtrim(mb_strtolower(trim($domain)), '.');
        if ($domain === '') {
            return '';
        }
        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? strtolower($ascii) : $domain;
    }

    /** @return array<string, mixed> */
    private function made(Service $service, string $username, string $password, string $domain, mixed $ip): array
    {
        $service->properties()->where('key', self::PENDING)->delete();
        $service->properties()->updateOrCreate([
            'key' => 'directadmin_username',
        ], [
            'name' => 'DirectAdmin username',
            'value' => $username,
        ]);

        // The password goes to the service's own field, encrypted and kept out of audits and serialization, not to a
        // property (stored in clear, audited, shown to staff: sweep §6 #2). Modules read it back as `password`.
        $service->forceFill(['username' => $username, 'password' => $password])->save();

        return [
            'username' => $username,
            'password' => $password,
            'domain' => $domain,
            'ip' => $ip,
        ];
    }

    /**
     * Suspend a server
     *
     * @param  array  $settings  (product settings)
     * @param  array  $properties  (checkout options)
     * @return bool
     */
    public function suspendServer(Service $service, $settings, $properties)
    {
        $properties = self::account($service, $properties);
        $this->ownAccount($service, $properties);

        $response = $this->request('/CMD_API_SELECT_USERS', 'post', [
            'location' => 'CMD_SELECT_USERS',
            'suspend' => 'suspend',
            'select0' => $properties['directadmin_username'],
        ], parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error suspending DirectAdmin account: ' . $response['text']);
        }

        return true;
    }

    /**
     * Unsuspend a server
     *
     * @param  array  $settings  (product settings)
     * @param  array  $properties  (checkout options)
     * @return bool
     */
    public function unsuspendServer(Service $service, $settings, $properties)
    {
        // Not refused for an account another service shares (as cPanel's): an unsuspension never takes one away.
        $properties = self::account($service, $properties, shared: true);
        $this->ownAccount($service, $properties); // nor a stranger's, suspended in DirectAdmin for its own reason (as cPanel's)

        $response = $this->request('/CMD_API_SELECT_USERS', 'post', [
            'location' => 'CMD_SELECT_USERS',
            'suspend' => 'unsuspend',
            'select0' => $properties['directadmin_username'],
        ], parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error unsuspending DirectAdmin account: ' . $response['text']);
        }

        return true;
    }

    /**
     * Terminate a server
     *
     * @param  array  $settings  (product settings)
     * @param  array  $properties  (checkout options)
     * @return bool
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        $properties = self::account($service, $properties);
        $this->ownAccount($service, $properties);

        $response = $this->request('/CMD_API_SELECT_USERS', 'post', [
            'confirmed' => 'Confirm',
            'delete' => 'yes',
            'select0' => $properties['directadmin_username'],
        ], parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error terminating DirectAdmin account: ' . $response['text']);
        }

        // Delete the properties, and the login with them; the native username too (as cPanel's, review R2): the account is
        // gone, a later action must not name it (the name may be another customer's account by then).
        $service->properties()->whereIn('key', ['directadmin_username', 'directadmin_password'])->delete();
        $service->forceFill(['username' => null, 'password' => null])->save();

        return true;
    }

    public function getActions(Service $service, $settings, $properties): array
    {
        $properties = self::withNativeUsername($properties);
        if (!isset($properties['directadmin_username']) || self::password($properties) === null) {
            return [];
        }

        return [
            [
                'label' => 'Access DirectAdmin',
                'type' => 'button',
                // Into the panel: `productsso` (WHMCS; docs/accounts.md, PR 2b).
                'sso' => true,
                'function' => 'ssoLink',
            ],
        ];
    }

    public function ssoLink(Service $service, $settings, $properties): string
    {
        $properties = self::withNativeUsername($properties);
        if (!isset($properties['directadmin_username']) || self::password($properties) === null) {
            return '';
        }

        $response = $this->panel()->withBasicAuth($properties['directadmin_username'], self::password($properties))
            ->post(rtrim(trim((string) $this->config('host')), '/') . '/CMD_API_LOGIN_KEYS', [
                'action' => 'create',
                'type' => 'one_time_url',
                'expiry' => '5m',
            ])->throw();

        $response = $this->parse($response);

        if (!isset($response['error'])) {
            throw new Exception('Unexpected DirectAdmin response while creating SSO link');
        }

        if ($response['error'] != '0') {
            throw new Exception('Error creating DirectAdmin SSO link: ' . ($response['text'] ?? 'Unknown error'));
        }

        return $response['details'] ?? '';
    }

    /** The account's password: the service's own (encrypted) field; a property only on accounts made before. */
    /**
     * Its account name: the module's own property or, for an account imported from WHMCS, the service's native username
     * (tblhosting.username; the importer sets no directadmin_username: sweep PROVISIONING-6).
     */
    private static function withNativeUsername(array $properties): array
    {
        $name = $properties['directadmin_username'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            $native = $properties['username'] ?? null;
            unset($properties['directadmin_username']);
            if (is_string($native) && trim($native) !== '') {
                $properties['directadmin_username'] = trim($native);
            }
        }

        return $properties;
    }

    /**
     * The service's account, to suspend, unsuspend or remove: named and, unless $shared is allowed, no other live service
     * here has the same one (sweep PROVISIONING-3).
     */
    private static function account(Service $service, array $properties, bool $shared = false): array
    {
        $properties = self::withNativeUsername($properties);
        if (!isset($properties['directadmin_username'])) {
            throw new Exception('Service has not been created');
        }
        if (!$shared && ($other = SharedAccount::otherLive($service, $properties['directadmin_username'], ['directadmin_username'])) !== null) {
            throw new Exception("DirectAdmin: service #{$other->id} has the account «{$properties['directadmin_username']}» on this server too: check both before anything is done to it (by hand)");
        }

        return $properties;
    }

    /**
     * DirectAdmin's account by that name is this service's: its domain is the service's (sweep 10, S10-PROVISIONING-2, as
     * cPanel's ownAccount and mailcow's ownedAdmin). A username kept from WHMCS, or of an account removed and made again
     * in DirectAdmin for someone else, names a stranger's account: never suspended, unsuspended or removed from here;
     * staff check it (the job's refusal is their task). A lookup that fails throws, before anything is sent. No account
     * by that name, or none DirectAdmin names a domain for, or a service with no domain: DirectAdmin's own answer to the
     * action tells, as before. The same domain written another way (case, «www.», a dot at its end, an IDN) is the same.
     */
    private function ownAccount(Service $service, array $properties): void
    {
        $username = $properties['directadmin_username'];
        $config = $this->request('/CMD_API_SHOW_USER_CONFIG', 'get', ['user' => $username], parse: true);
        $theirs = is_array($config) ? ($config['domain'] ?? null) : null;
        $ours = filled($service->domain) ? $service->domain : ($properties['domain'] ?? null);
        if (!is_string($theirs) || trim($theirs) === '' || !is_string($ours) || trim($ours) === '') {
            return;
        }
        $plain = fn (string $domain): string => preg_replace('/^www\./', '', self::comparableDomain($domain)) ?? '';
        if ($plain($theirs) !== $plain($ours)) {
            throw new Exception("DirectAdmin: the account «{$username}» on this server is for {$theirs}, not this service's domain ({$ours}): check the service's username and domain before anything is done to it (by hand)");
        }
    }

    private static function password(array $properties): ?string
    {
        $password = $properties['password'] ?? $properties['directadmin_password'] ?? null;

        return is_string($password) && $password !== '' ? $password : null;
    }
}
