<?php

namespace MyCS\Extensions\Servers\CPanel;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Helpers\NotificationHelper;
use App\Models\ModuleAction;
use App\Models\Service;
use App\Rules\Domain;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

#[ExtensionMeta(
    name: 'cPanel',
    description: 'cPanel server extension',
    version: 'builtin',
    author: 'MyCS',
    icon: 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTA0IiBoZWlnaHQ9IjcwIiB2aWV3Ym94PSIwIDAgMTA0IDcwIiBmaWxsPSJub25lIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGNsYXNzPSJob3ZlcjpzY2FsZS0xMjAgZmxleCBpdGVtcy1jZW50ZXIganVzdGlmeS1jZW50ZXIgc2l6ZS0xOCByb3VuZGVkLXhsIHAtNCBiZy1bI0ZGNkMyQ10gcmluZy02IHJpbmctWyNGRjZDMkNdLzMwIj48cGF0aCBkPSJNMjYuMjI2IDE3LjQ0OTRINDUuODQwN0w0Mi44MDYgMjkuMDc5NEM0Mi4zODg4IDMwLjYzMTEgNDEuNDc0MSAzMi4wMDMxIDQwLjIwMjIgMzIuOTg1QzM4LjkzNzUgMzMuOTY2IDM3LjM3NjIgMzQuNDg2NCAzNS43NzU5IDM0LjQ2MDVIMjYuNjU5OUMyNC42NzQ5IDM0LjQzMyAyMi43Mzg1IDM1LjA3NTEgMjEuMTYzMiAzNi4yODMxQzE5LjU0OTIgMzcuNTMyNSAxOC4zNzgxIDM5LjI2NjMgMTcuODIxNyA0MS4yMzAyQzE3LjQxOTkgNDIuNjczMiAxNy4zNzUyIDQ0LjE5MjMgMTcuNjkxNSA0NS42NTY1QzE3Ljk4MjIgNDYuOTkxOSAxOC41NTUzIDQ4LjI0OTYgMTkuMzcyNCA0OS4zNDUxQzIwLjIwMDMgNTAuNDMwMiAyMS4yNzAyIDUxLjMwNyAyMi40OTY5IDUxLjkwNTRDMjMuNzkxMSA1Mi41NDY5IDI1LjIxODQgNTIuODc0IDI2LjY2MjggNTIuODYwMUgzMi4yMTc0QzMyLjcxMzUgNTIuODUxNiAzMy4yMDQ0IDUyLjk2MTMgMzMuNjQ5NiA1My4xODAxQzM0LjA5NDkgNTMuMzk4OSAzNC40ODE2IDUzLjcyMDcgMzQuNzc3OCA1NC4xMTg2QzM1LjEwMjMgNTQuNTAyOSAzNS4zMjUzIDU0Ljk2MjUgMzUuNDI2NCA1NS40NTUzQzM1LjUyNzUgNTUuOTQ4IDM1LjUwMzQgNTYuNDU4MyAzNS4zNTY0IDU2LjkzOTNMMzEuODg0OCA2OS43ODQ0SDI1LjgwOTRDMjEuODEwNSA2OS44MjQzIDE3Ljg2MjYgNjguODg1OCAxNC4zMDk2IDY3LjA1MDVDMTAuODk3NSA2NS4zMDg5IDcuOTEyOTMgNjIuODM0MSA1LjU2OTczIDU5LjgwMzRDMy4yNDY1IDU2Ljc3NTggMS42MzAzIDUzLjI2NjYgMC44Mzk2MTcgNDkuNTMzMkMwLjAwMDc4NzIyOCA0NS42MTA5IDAuMTA1ODk0IDQxLjU0NTQgMS4xNDYyOCAzNy42NzE3TDEuNDkzNDQgMzYuMzY5OUMyLjk2MTU4IDMwLjkzMjIgNi4xNzgxNyAyNi4xMjg2IDEwLjY0NyAyMi43MDAzQzEyLjgzODUgMjEuMDM4NiAxNS4yNzk3IDE5LjczNSAxNy44Nzk2IDE4LjgzODFDMjAuNTYzNCAxNy45MDc1IDIzLjM4NTQgMTcuNDM4IDI2LjIyNiAxNy40NDk0WiIgZmlsbD0id2hpdGUiPjwvcGF0aD48cGF0aCBkPSJNMzYuMTIwMSA2OS43ODM2TDUzLjIyMDggNS43MzE4NUM1My42MzggNC4xODAxNiA1NC41NTI3IDIuODA4MTYgNTUuODI0NiAxLjgyNjI2QzU3LjA4OCAwLjg0NTA5OSA1OC42NDg1IDAuMzI0NTg4IDYwLjI0OCAwLjM1MDgxMUg3OC4zOTAyQzgyLjM4OSAwLjMxMTIzMiA4Ni4zMzY5IDEuMjQ5NzggODkuODkgMy4wODQ3M0M5My4yOTg2IDQuODIzNzQgOTYuMjc3OCA3LjI5ODkzIDk4LjYxMTkgMTAuMzMxMUMxMDAuOTQ2IDEzLjM2MzMgMTAyLjU3NyAxNi44NzY1IDEwMy4zODYgMjAuNjE2NUMxMDQuMjU1IDI0LjUzOCAxMDQuMTM1IDI4LjYxMzkgMTAzLjAzOSAzMi40Nzc5TDEwMi42OTIgMzMuNzc5OEMxMDEuOTYgMzYuNTExOSAxMDAuNzg3IDM5LjEwNjMgOTkuMjIwMSA0MS40NjA4Qzk2LjkwMTEgNDQuOTUxNyA5My43NTMgNDcuODEzNCA5MC4wNTc0IDQ5Ljc4OTlDODYuMzYxOCA1MS43NjY0IDgyLjIzNCA1Mi43OTYyIDc4LjA0MzEgNTIuNzg3SDYyLjMzMzlMNjUuNDU4NCA0MS4wNzAyQzY1Ljg5MjYgMzkuNTUwMiA2Ni44MDU3IDM4LjIxMDkgNjguMDYyMSAzNy4yNTE0QzY5LjMyNjggMzYuMjcwNSA3MC44ODgxIDM1Ljc1IDcyLjQ4ODQgMzUuNzc2SDc3LjUyMjNDNzkuNTQ5NyAzNS43NzgyIDgxLjUyMDIgMzUuMTA1NiA4My4xMjMxIDMzLjg2NDFDODQuNzI2IDMyLjYyMjcgODUuODcwMSAzMC44ODMgODYuMzc1IDI4LjkxOTVDODYuNzc0NCAyNy41Mjk5IDg2LjgxNDIgMjYuMDYxNSA4Ni40OTA3IDI0LjY1MjNDODYuMTk2NiAyMy4zMTYgODUuNjE5NSAyMi4wNTgyIDg0Ljc5ODMgMjAuOTYzN0M4My45NjM3IDE5Ljg3MzkgODIuODk2MiAxOC45ODQzIDgxLjY3MzggMTguMzU5OUM4MC4zOTIxIDE3LjY5MzYgNzguOTY2OCAxNy4zNTEgNzcuNTIyMyAxNy4zNjE4SDY3LjgwMTdMNTUuMTMwMiA2NC40ODkzQzU0LjY5NiA2Ni4wMDk0IDUzLjc4MjkgNjcuMzQ4NyA1Mi41MjY1IDY4LjMwODFDNTEuMjkgNjkuMjc5NSA0OS43NTkyIDY5Ljc5OTkgNDguMTg3IDY5Ljc4MzZIMzYuMTIwMVoiIGZpbGw9IndoaXRlIj48L3BhdGg+PC9zdmc+',
)]
class CPanel extends Server
{
    private function request($endpoint, $method = 'get', $data = [])
    {
        // A pasted token or host often carries a space or line break, which WHM answers as a bad token.
        $host = rtrim(trim((string) $this->config('host')), '/');
        if ($host === '') {
            throw new Exception('This server has no host set: enter its WHM address (https://host:2087).'); // not a request to nowhere
        }
        // A server that never answers is told within 10 seconds, not the default 30 (the call itself may take longer).
        $response = Http::connectTimeout(10)->withHeaders([
            'Authorization' => 'whm ' . trim((string) $this->config('username')) . ':' . trim((string) $this->config('apikey')),
        ])->$method($host . '/json-api' . $endpoint, $data)->throw();

        return $response;
    }

    /**
     * A WHM API 1 call that must succeed: WHM refuses with HTTP 200 and metadata.result = 0, so the HTTP status
     * alone says nothing. Throws with WHM's reason.
     *
     * @return array<string, mixed>
     */
    private function whm(string $endpoint, array $data, string $method = 'get'): array
    {
        $json = $this->request($endpoint, $method, ['api.version' => 1, ...$data])->json();

        if (($json['metadata']['result'] ?? null) !== 1) {
            throw new Exception('cPanel ' . ltrim($endpoint, '/') . ' failed: ' . ($json['metadata']['reason'] ?? 'no reason given'));
        }

        return $json;
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
                'type' => 'text',
                'label' => 'Hostname',
                'placeholder' => 'https://example.com:2087',
                'validation' => 'url:http,https',
                'required' => true,
            ],
            [
                'name' => 'username',
                'type' => 'text',
                'placeholder' => 'johndoe',
                'label' => 'Username',
                'required' => true,
            ],
            [
                'name' => 'apikey',
                'type' => 'password',
                'placeholder' => '1234567890abcdef',
                'label' => 'API key',
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
        // Get all the packages
        $packages = $this->request('/listpkgs')->json();
        $packageOptions = [];
        foreach ($packages['package'] as $package) {
            $packageOptions[] = [
                'value' => $package['name'],
                'label' => $package['name'],
            ];
        }

        return [
            [
                'name' => 'package',
                'type' => 'select',
                'label' => 'Package',
                'options' => $packageOptions,
                'required' => true,
            ],
        ];
    }

    /**
     * The admin's «Test Connection» (and the MCP's server_test): true, or why not, never an exception. WHM refuses a
     * token or a privilege with HTTP 200 and metadata.result 0, so that is read too, not only the HTTP status;
     * a cheap call (version) that any reseller or root token may make.
     */
    public function testConfig(): bool|string
    {
        try {
            $this->whm('/version', []);
        } catch (RequestException $e) {
            $json = $e->response->json();
            $reason = is_array($json) ? ($json['metadata']['reason'] ?? $json['statusmsg'] ?? $json['cpanelresult']['error'] ?? null) : null;

            return 'WHM answered HTTP ' . $e->response->status() . (is_string($reason) && $reason !== '' ? ': ' . $reason : '');
        } catch (ConnectionException $e) {
            return 'Could not reach WHM: ' . $e->getMessage();
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return true;
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
     * Create a server
     *
     * @param  array  $settings  (product settings)
     * @param  array  $properties  (checkout options)
     * @return bool
     */
    public function createServer(Service $service, $settings, $properties)
    {
        // Idempotent (sweep §2): the username is kept before the account is asked for, so a retry after a lost answer
        // (a timeout once WHM made it) asks WHM whether that account exists instead of making a second one under a new
        // name. Kept as «pending» until made: suspend/terminate never act on an account that may not exist.
        $domain = $properties['domain'] ?? $service->domain;
        $pending = $service->properties()->where('key', 'cpanel_pending_username')->value('value');
        if (is_string($pending) && $pending !== '') {
            $account = $this->account($pending);
            if ($account !== null && self::sameDomain($account['domain'] ?? null, $domain)) {
                return $this->made($service, $pending, $settings, $properties); // made by the attempt whose answer was lost
            }
            if ($account !== null) {
                // That name is someone else's account (review R2): never adopted; the next attempt draws another.
                $service->properties()->where('key', 'cpanel_pending_username')->delete();
                throw new Exception("cPanel createacct: the name {$pending} belongs to another account on this server; retry with a new one");
            }
        }
        $username = is_string($pending) && $pending !== '' ? $pending : chr(random_int(97, 122)) . Str::lower(Str::random(7)); // a letter first, lowercase
        $service->properties()->updateOrCreate(['key' => 'cpanel_pending_username'], ['name' => 'cPanel username (being created)', 'value' => $username]);

        $json = $this->request('/createacct', 'get', ['api.version' => 1,
            'username' => $username,
            'contactemail' => $service->client->email,
            'domain' => $domain,
            'plan' => $this->package($settings, $properties),
        ])->json();
        if (($json['metadata']['result'] ?? null) !== 1) {
            // Refused (not lost): nothing was made under that name, and a refusal tied to it must not repeat for ever.
            $service->properties()->where('key', 'cpanel_pending_username')->delete();
            throw new Exception('cPanel createacct failed: ' . ($json['metadata']['reason'] ?? 'no reason given'));
        }

        return $this->made($service, $username, $settings, $properties);
    }

    /**
     * The account is made (now, or adopted after a lost answer): kept, then its extra disk set. One that fails is not a
     * failed create (a retry would make the account again): a Change Package for staff at once (its Retry sets it), and
     * a mail (reviews of #296).
     */
    private function made(Service $service, string $username, array $settings, array $properties): bool
    {
        $this->created($service, $username, $properties);
        try {
            $this->applyExtraDisk($service, $username, $this->package($settings, $properties), $properties);
        } catch (Throwable $e) {
            report($e);
            $error = new Exception('The extra disk space was not set after the account was made: ' . $e->getMessage());
            if (ModuleAction::giveUp($service, 'upgrade', $error)) {
                NotificationHelper::sendSystemEmailNotification("Module action failed: upgrade of service #{$service->id}", e(
                    "{$error->getMessage()}. It is listed in Pending Module Actions as a Change Package: Retry it there once the cause is fixed."
                ));
            }
        }

        return true;
    }

    /**
     * Extra disk space (owner 2026-10-21, in steps of 10GB): the option «extra_disk_gb», its value the GB (a select:
     * +10GB → 10; a whole number, never more than written: «2.5» is 2). The account's quota is its package's plus that,
     * set with WHM's editquota; none again (a downgrade, a product without the option): its package's. What it set is kept
     * on the service (cpanel_extra_disk_gb), so a product change that drops the option takes it off (reviews of #296);
     * a service that never had one is not touched (a quota staff set in WHM stays). A package without a limit needs none.
     * changepackage puts the package's quota back, so it is set after.
     */
    private function applyExtraDisk(Service $service, string $username, string $package, array $properties): void
    {
        $had = (int) $service->properties()->where('key', 'cpanel_extra_disk_gb')->value('value');
        if (!array_key_exists('extra_disk_gb', $properties) && $had <= 0) {
            return;
        }
        $extra = self::gigabytes($properties['extra_disk_gb'] ?? 0);
        $info = $this->whm('/getpkginfo', ['pkg' => $package]);
        $quota = $info['data']['pkg']['QUOTA'] ?? null;
        if (!is_numeric($quota) || (int) $quota <= 0) {
            return; // unlimited (or not told): nothing to add to
        }
        $this->whm('/editquota', ['user' => $username, 'quota' => (int) $quota + $extra * 1024]);
        if ($extra > 0) {
            $service->properties()->updateOrCreate(['key' => 'cpanel_extra_disk_gb'], ['name' => 'Extra disk space (GB)', 'value' => (string) $extra]);
        } else {
            $service->properties()->where('key', 'cpanel_extra_disk_gb')->delete();
        }
    }

    /** «10», «+10GB», «10 GB» → 10; «2.5» → 2; anything else (negative, words) → 0. */
    private static function gigabytes(mixed $value): int
    {
        $text = trim((string) $value);
        if (is_numeric($text)) {
            return max(0, (int) floor((float) $text));
        }

        return preg_match('/^\+?\s*(\d+)\s*(gb|g)?$/i', $text, $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * The service's package as WHM knows it: a config option «package» first, else the product's. A reseller's own
     * packages are «reseller_package» in WHM, and WHMCS stores them without the prefix (an imported «V16» is the
     * reseller's «…_V16»); a global (root's) package a reseller may use has none. With a reseller's login the name is
     * resolved against what WHM lists for it: as stored if listed, else with the prefix (as WHMCS's cPanel module), else
     * refused with why (test on a reseller of nixpal, 2026-10-20: «"V16" is not a valid package name»). Root's: as stored.
     */
    private function package(array $settings, array $properties): string
    {
        $package = trim((string) ($properties['package'] ?? $settings['package'] ?? ''));
        $reseller = strtolower(trim((string) $this->config('username')));
        if ($reseller === '' || $reseller === 'root' || $package === '') {
            return $package;
        }
        $names = array_column($this->request('/listpkgs')->json()['package'] ?? [], 'name');
        if ($names === []) {
            // Not listed (an old WHM, a refused call): WHMCS's rule, prefixed unless it is already.
            return str_starts_with($package, $reseller . '_') ? $package : $reseller . '_' . $package;
        }
        if (in_array($package, $names, true)) {
            return $package;
        }
        if (in_array($reseller . '_' . $package, $names, true)) {
            return $reseller . '_' . $package;
        }

        throw new Exception("cPanel: package «{$package}» is not one this server's login ({$reseller}) may use: set the product's package");
    }

    /** @return array<string, mixed>|null WHM's account by that name, or none */
    private function account(string $username): ?array
    {
        $json = $this->request('/accountsummary', 'get', ['api.version' => 1, 'user' => $username])->json();

        return ($json['metadata']['result'] ?? null) === 1 ? ($json['data']['acct'][0] ?? []) : null;
    }

    private static function sameDomain(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && strtolower(trim($a)) === strtolower(trim($b));
    }

    private function created(Service $service, string $username, array $properties): bool
    {
        $service->properties()->updateOrCreate([
            'key' => 'cpanel_username',
        ], [
            'name' => 'cPanel username',
            'value' => $username,
        ]);
        $service->properties()->where('key', 'cpanel_pending_username')->delete();
        // The service's own fields too, as WHMCS keeps them (shown to staff and the customer, searched).
        $service->forceFill(['username' => $username, 'domain' => $service->domain ?? $properties['domain']])->save();

        return true;
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
        if (!isset($properties['cpanel_username'])) {
            throw new Exception('Service has not been created');
        }

        $this->whm('/suspendacct', [
            'user' => $properties['cpanel_username'],
        ]);

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
        if (!isset($properties['cpanel_username'])) {
            throw new Exception('Service has not been created');
        }

        $this->whm('/unsuspendacct', [
            'user' => $properties['cpanel_username'],
        ]);

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
        if (!isset($properties['cpanel_username'])) {
            // A create whose answer was lost (review R5): WHM may have made the account under the pending name.
            $pending = $properties['cpanel_pending_username'] ?? null;
            $account = is_string($pending) && $pending !== '' ? $this->account($pending) : null;
            if ($account === null || !self::sameDomain($account['domain'] ?? null, $service->domain ?? ($properties['domain'] ?? null))) {
                throw new Exception('Service has not been created');
            }
            $this->whm('/removeacct', ['user' => $pending]);
            $service->properties()->where('key', 'cpanel_pending_username')->delete();

            return true;
        }

        $this->whm('/removeacct', [
            'user' => $properties['cpanel_username'],
        ]);

        // Delete the properties, and the native username (review R2): the account is gone, a later action must not name
        // it (the same username may belong to another customer's account by then).
        $service->properties()->where('key', 'cpanel_username')->delete();
        $service->forceFill(['username' => null])->save();

        return true;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        if (!isset($properties['cpanel_username'])) {
            throw new Exception('Service has not been created');
        }

        // WHM's «Change Package» (sweep §2/§4): modifyacct ignores a `plan` (an «Unrecognized modifyacct parameter»
        // warning in WHM's log) and answers result 1, so the package never changed. Verified on WHM 138 (2026-10-15).
        // The service's package as create chose it: a config option «package» first (review R1a). Only when it changed
        // (review R1b, as WHMCS): changepackage re-applies the package's limits and shell, undoing what staff set in WHM.
        $package = $this->package($settings, $properties);
        if (($this->account($properties['cpanel_username'])['plan'] ?? null) !== $package) {
            $this->whm('/changepackage', [
                'user' => $properties['cpanel_username'],
                'pkg' => $package,
            ]);
        }
        // Its extra disk as it is now: added, changed or taken off by this upgrade, or put back after changepackage.
        $this->applyExtraDisk($service, $properties['cpanel_username'], $package, $properties);

        return true;
    }

    public function getLoginUrl(Service $service, $settings, $properties): string
    {
        if (!isset($properties['cpanel_username'])) {
            throw new Exception('Service has not been created');
        }

        $response = $this->request(
            '/create_user_session',
            'post',
            [
                'api.version' => 1,
                'user' => $properties['cpanel_username'],
                'service' => 'cpaneld',
            ]
        )->json();

        if (isset($response['data']['url'])) {
            $url = $response['data']['url'];

            return $url;
        }

        throw new Exception('Unable to generate cPanel login URL');
    }

    public function getActions(Service $service, $settings, $properties): array
    {
        if (!isset($properties['cpanel_username'])) {
            return [];
        }

        return [
            [
                'label' => 'Access cPanel',
                'type' => 'button',
                // Into the panel: `productsso` (WHMCS; docs/accounts.md, PR 2b).
                'sso' => true,
                'function' => 'getLoginUrl',
            ],
        ];
    }
}
