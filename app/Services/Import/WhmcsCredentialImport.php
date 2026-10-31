<?php

namespace App\Services\Import;

use App\Models\DomainRegistrarConnection;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\Server;
use Closure;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The logins WHMCS keeps for what the staff connect: a cPanel server's API token, a registrar account, a payment
 * gateway's keys. WHMCS keeps the registrars' and gateways' settings encrypted with its cc_encryption_hash, which is not
 * in its database, so they are read through its API (DecryptPassword), never by reading the key, as the services'
 * passwords and the saved payment methods (WhmcsServicePasswordImport, WhmcsPayMethodImport).
 *
 * - A set is written only where none is stored yet: what staff typed here stays.
 * - Nothing is switched on. A registrar connection stays inactive (its mode follows its account, Production or Sandbox: a
 *   mode «off» would send a live password to a registrar's test system) and a gateway disabled, and nothing is asked of
 *   Stripe, PayPal or a registrar: the values are written as Settings, never through an extension's own save (Stripe's
 *   creates a webhook endpoint there). The webhooks of Stripe and PayPal are this instance's own, made at the cut-over.
 * - A value is never printed, logged nor kept anywhere else: the notes carry modules, setting names and server ids.
 * - cPanel: MyCS logs in to WHM with an API token, which a WHMCS server can keep in its access hash. A server that logs
 *   in with a password has no token to bring: said, and staff make one (WHM → Manage API Tokens).
 */
final class WhmcsCredentialImport
{
    /** WHMCS's answers that hold for every call (its credentials, role, allowed IPs): the step stops at the first. */
    private const REFUSED = '/permission|not allowed|authenticat|invalid ip|ip address|access denied|credentials|identifier|secret/i';

    /** Failures in a row (a WHMCS that hangs) after which the rest is not asked. */
    private const IN_A_ROW = 5;

    /** A WHM API token as WHM makes it. */
    private const WHM_TOKEN = '/^[A-Za-z0-9]{20,64}$/';

    /** MyCS registrar => the WHMCS modules that are the same account (ispapi is CNIC's older name), in the order tried. */
    public const REGISTRARS = ['cnic' => ['cnic', 'ispapi'], 'openprovider' => ['openprovider']];

    /** @var array<string, int> */
    private array $counts = ['found' => 0, 'imported' => 0, 'skipped' => 0];

    /** @var list<string> */
    private array $notes = [];

    private bool $stopped = false;

    private bool $needsApi = false;

    private int $inARow = 0;

    /** @param  Closure(string): ?int  $gateway  a WHMCS gateway module => the id of its MyCS gateway (installed, disabled, when none is) */
    private readonly WhmcsSecondFactor $scheme;

    private bool $needsKey = false;

    /** WHMCS 8's scheme for a value: `<iv hex>.<ciphertext hex>` (the gateways' settings), which needs its cc_encryption_hash. */
    private const NEW_SCHEME = '/^[0-9a-f]{32}\.[0-9a-f]{2,}$/i';

    public function __construct(private readonly PDO $whmcs, private readonly ?WhmcsApi $api, private readonly Closure $gateway, ?WhmcsSecondFactor $scheme = null)
    {
        $this->scheme = $scheme ?? WhmcsSecondFactor::fromEnv();
    }

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        $this->servers();
        $this->registrars();
        $this->gateways();
        if ($this->needsKey) {
            $this->notes[] = 'Gateway settings not imported: WHMCS keeps them in its newer scheme, which needs its cc_encryption_hash '
                . '(WHMCS_CC_ENCRYPTION_HASH in the environment, from WHMCS\'s configuration.php; the same key brings the 2FA).';
        }
        if ($this->needsApi || $this->needsKey) { // the closing notes say why: not each item's «not read»
            $this->notes = array_values(array_filter($this->notes, fn (string $note) => !str_contains($note, 'was not read: enter it by hand') && !str_contains($note, 'were not decrypted')));
        }
        if ($this->needsApi) {
            $this->notes[] = "Credentials of registrars and gateways not imported: WHMCS's API is needed to decrypt them "
                . '(--whmcs-api-url, WHMCS_API_IDENTIFIER, WHMCS_API_SECRET in the environment).';
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    // ---- servers ----------------------------------------------------------------------------------------------------

    private function servers(): void
    {
        foreach ($this->rows('SELECT id, name, type, accesshash FROM tblservers ORDER BY id') as $row) {
            $server = Server::query()->where('whmcs_id', (int) $row['id'])->first();
            if ($server === null) {
                continue; // none of the module's extensions here: the catalog step said so
            }
            $this->counts['found']++;
            $label = "Server #{$row['id']} «{$row['name']}»";
            if (strtolower((string) $row['type']) !== 'cpanel') {
                $this->skip("{$label}: its login is not carried for the module «{$row['type']}»: enter it by hand.");

                continue;
            }
            $token = (string) preg_replace('/\s+/', '', (string) ($row['accesshash'] ?? ''));
            if ($token === '') {
                $this->skip("{$label} logs in to WHM with a password in WHMCS: MyCS uses an API token. Make one in WHM (Manage API Tokens) and enter it as the server's API key.");
            } elseif (preg_match(self::WHM_TOKEN, $token) !== 1) {
                $this->skip("{$label}: its access hash is not the shape of an API token: make a token in WHM (Manage API Tokens) and enter it as the server's API key.");
            } elseif ($this->has($server, 'apikey')) {
                $this->skip("{$label}: an API key is already set here, kept.");
            } else {
                $server->settings()->create(['key' => 'apikey', 'value' => $token, 'type' => 'string', 'encrypted' => true]);
                $this->counts['imported']++;
            }
            unset($token);
        }
    }

    // ---- registrars -------------------------------------------------------------------------------------------------

    private function registrars(): void
    {
        $sets = [];
        foreach ($this->rows('SELECT registrar, setting, value FROM tblregistrars') as $row) {
            $sets[strtolower(trim((string) $row['registrar']))][trim((string) $row['setting'])] = (string) $row['value'];
        }
        if ($sets === []) {
            return;
        }
        $known = array_merge(...array_values(self::REGISTRARS));
        foreach (array_diff(array_keys($sets), $known) as $module) {
            $this->notes[] = "Registrar «{$module}» in WHMCS has no connection here: its settings are not carried.";
        }
        $used = $this->domainsByRegistrar();
        foreach (self::REGISTRARS as $registrar => $modules) {
            $connection = DomainRegistrarConnection::query()->where('registrar', $registrar)->orderBy('id')->first();
            $complete = array_values(array_filter($modules, fn (string $m) => ($sets[$m]['Username'] ?? '') !== '' && ($sets[$m]['Password'] ?? '') !== ''));
            if ($connection === null || ($complete === [] && !array_intersect($modules, array_keys($sets)))) {
                continue;
            }
            $this->counts['found']++;
            $credentials = $connection->credentials();
            if (($credentials['username'] ?? '') !== '' && ($credentials['password'] ?? '') !== '') {
                $this->skip("Registrar «{$registrar}»: a login is already set here, kept.");

                continue;
            }
            if ($complete === []) {
                $this->skip("Registrar «{$registrar}»: WHMCS has no username and password for it.");

                continue;
            }
            // The account in use: the module most of the domains name (their `registrar`), the first listed when level.
            usort($complete, fn (string $a, string $b) => ($used[$b] ?? 0) <=> ($used[$a] ?? 0) ?: array_search($a, $modules, true) <=> array_search($b, $modules, true));
            $module = $complete[0];
            // Live or test decides the mode it is stored in: «off» would reach the registrar's sandbox (Openprovider's over plain
            // HTTP) with a live password when staff press Test Connection (ADV-561-2). Unknown, it is not stored.
            $test = $this->sandboxFlag($sets[$module]['TestMode'] ?? $sets[$module]['test_mode'] ?? '', "registrar {$module} test mode");
            $username = $test === null ? null : $this->plain($sets[$module]['Username'], "registrar {$module} username");
            $password = $username === null ? null : $this->plain($sets[$module]['Password'], "registrar {$module} password");
            if ($test === null || $username === null || $password === null || trim($username) === '' || $password === '') {
                $this->skip("Registrar «{$registrar}»: its login, or whether it is the live or the test account, was not read: enter it by hand.");

                continue;
            }
            $sandbox = $test;
            $connection->storeCredentials(['username' => trim($username), 'password' => $password], ['password']);
            // Inactive: nothing calls it (every call needs is_active); only staff's Test Connection reaches the system its mode names.
            $connection->forceFill(['mode' => $sandbox ? 'sandbox' : 'production'])->save();
            $this->counts['imported']++;
            if (count($complete) > 1) {
                $this->notes[] = "Registrar «{$registrar}»: WHMCS has settings for both «" . implode('» and «', $complete) . "»; the one most domains use, «{$module}», was taken. Check it is the account in use.";
            }
            $this->notes[] = "Registrar «{$registrar}»: the login is " . ($sandbox ? 'WHMCS\'s TEST account: mode Sandbox' : 'WHMCS\'s live account: mode Production')
                . '; the connection stays inactive until staff switch it on (Domains → Registrars).';
            unset($username, $password, $test);
        }
    }

    /** @return array<string, int> domains per WHMCS registrar module, as tbldomains names them */
    private function domainsByRegistrar(): array
    {
        $count = [];
        foreach ($this->rows('SELECT registrar, COUNT(*) AS n FROM tbldomains GROUP BY registrar') as $row) {
            $count[strtolower(trim((string) $row['registrar']))] = (int) $row['n'];
        }

        return $count;
    }

    // ---- gateways ---------------------------------------------------------------------------------------------------

    private function gateways(): void
    {
        $sets = [];
        foreach ($this->rows('SELECT gateway, setting, value FROM tblpaymentgateways') as $row) {
            $sets[strtolower(trim((string) $row['gateway']))][trim((string) $row['setting'])] = (string) $row['value'];
        }
        if (isset($sets['stripe'])) {
            $this->stripe($sets['stripe']);
        }
        if (isset($sets['paypal_ppcpv'])) {
            $this->paypal($sets['paypal_ppcpv']);
        }
        if (isset($sets['paypal'])) {
            $this->notes[] = 'Gateway «paypal» (the classic API) has no counterpart here: its keys are not carried.';
        }
    }

    /** @param  array<string, string>  $set */
    private function stripe(array $set): void
    {
        $this->counts['found']++;
        $extension = $this->extension('stripe');
        if ($extension === null) {
            $this->skip('Gateway «stripe»: MyCS has no gateway for it.');

            return;
        }
        if ($this->has($extension, 'stripe_secret_key')) {
            $this->skip('Gateway «stripe»: keys are already set here, kept.');

            return;
        }
        $secret = $this->plain($set['secretKey'] ?? '', 'stripe secretKey');
        $publishable = $this->plain($set['publishableKey'] ?? '', 'stripe publishableKey');
        $secret = $secret === null ? null : trim($secret);
        $publishable = $publishable === null ? null : trim($publishable);
        // The shapes only: a key of another kind, or a live one with a test one, is never stored half-right.
        if ($secret === null || $publishable === null || preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]+$/', $secret, $s) !== 1
            || preg_match('/^pk_(live|test)_[A-Za-z0-9]+$/', $publishable, $p) !== 1 || $s[2] !== $p[1]) {
            $this->skip('Gateway «stripe»: its keys were not decrypted, or are not a secret and a publishable key of the same mode: enter them by hand.');

            return;
        }
        $extension->settings()->create(['key' => 'stripe_secret_key', 'value' => $secret, 'type' => 'string', 'encrypted' => true]);
        $extension->settings()->create(['key' => 'stripe_publishable_key', 'value' => $publishable, 'type' => 'string', 'encrypted' => false]);
        $this->counts['imported']++;
        $this->notes[] = 'Gateway «stripe»: ' . ($s[2] === 'test' ? 'WHMCS holds TEST keys' : 'the live keys came')
            . '; it stays disabled. Its webhook endpoint is made by MyCS when staff save the gateway at the cut-over.';
        unset($secret, $publishable);
    }

    /** @param  array<string, string>  $set */
    private function paypal(array $set): void
    {
        $this->counts['found']++;
        $extension = $this->extension('paypal_ppcpv');
        if ($extension === null) {
            $this->skip('Gateway «paypal_ppcpv»: MyCS has no gateway for it.');

            return;
        }
        if ($this->has($extension, 'client_secret')) {
            $this->skip('Gateway «paypal_ppcpv»: keys are already set here, kept.');

            return;
        }
        $test = $this->sandboxFlag($set['useSandbox'] ?? '', 'paypal useSandbox');
        if ($test === null) {
            $this->skip('Gateway «paypal_ppcpv»: whether WHMCS runs the sandbox or the live app was not read: enter its client id and secret by hand.');

            return;
        }
        $id = $this->plain($set[$test ? 'sandboxClientId' : 'clientId'] ?? '', 'paypal client id');
        $secret = $this->plain($set[$test ? 'sandboxClientSecret' : 'clientSecret'] ?? '', 'paypal client secret');
        if ($id === null || $secret === null || trim($id) === '' || trim($secret) === '') {
            $this->skip('Gateway «paypal_ppcpv»: its client id and secret were not decrypted: enter them by hand.');

            return;
        }
        $extension->settings()->create(['key' => 'client_id', 'value' => trim($id), 'type' => 'string', 'encrypted' => false]);
        $extension->settings()->create(['key' => 'client_secret', 'value' => trim($secret), 'type' => 'string', 'encrypted' => true]);
        $extension->settings()->create(['key' => 'test_mode', 'value' => $test ? '1' : '0', 'type' => 'boolean', 'encrypted' => false]);
        $this->counts['imported']++;
        $this->notes[] = 'Gateway «paypal_ppcpv»: ' . ($test ? 'WHMCS runs in SANDBOX' : 'the live app\'s client id and secret came')
            . '; it stays disabled. Its webhook id is the new endpoint\'s: made and entered at the cut-over (importer.md).';
        unset($id, $secret);
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    /** The gateway as the app reads it: its Settings belong to the Gateway model, not to the base Extension (ADV-561-1). */
    private function extension(string $module): ?Gateway
    {
        $id = ($this->gateway)($module);

        return $id === null ? null : Gateway::query()->find($id);
    }

    private function has(Extension|Server $model, string $key): bool
    {
        return $model->settings()->where('key', $key)->whereNotNull('value')->where('value', '!=', '')->exists();
    }

    /**
     * Whether a WHMCS test/sandbox switch is on: true (test), false (live) or null (not told). No switch stored, or one that
     * decrypts to off, is live; one that could not be read, or says something else, is not guessed: a sandbox flag read as
     * live stores a test app's keys as live ones, and the reverse puts a live login on a test system (ADV2-561-1).
     */
    private function sandboxFlag(string $stored, string $what): ?bool
    {
        if (trim($stored) === '') {
            return false;
        }
        $value = $this->plain($stored, $what);
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim($value));

        return match (true) {
            in_array($value, ['on', '1', 'yes', 'true'], true) => true,
            in_array($value, ['', 'off', '0', 'no', 'false'], true) => false,
            default => null,
        };
    }

    private function skip(string $note): void
    {
        $this->counts['skipped']++;
        $this->notes[] = $note;
    }

    /**
     * What WHMCS keeps encrypted, decoded as WHMCS hands its modules (it keeps what staff typed HTML-escaped), or null:
     * nothing there, no API, WHMCS refused or stopped answering, or not text. An empty string is a value that decrypted
     * to nothing. Never printed: $what names the setting.
     */
    private function plain(string $stored, string $what): ?string
    {
        if (trim($stored) === '') {
            return null;
        }
        if (preg_match(self::NEW_SCHEME, trim($stored)) === 1) {
            // The gateways' settings: WHMCS's newer scheme, which its DecryptPassword answers with garbage for. Its key, not its API.
            if (!$this->scheme->available()) {
                $this->needsKey = true;

                return null;
            }
            $value = $this->scheme->plain(trim($stored));
            if ($value === null || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                report(new RuntimeException("Credential «{$what}» not decrypted: the key does not open it"));

                return null;
            }

            return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($this->api === null) {
            $this->needsApi = true;

            return null;
        }
        if ($this->stopped) {
            return null;
        }
        try {
            $answer = $this->api->call('DecryptPassword', ['password2' => $stored]);
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'WHMCS API «') && preg_match(self::REFUSED, $this->reason($e)) === 1) {
                $this->stopped = true;
                $this->notes[] = "Credentials not imported: WHMCS refused the API's call ({$this->reason($e)}). Check the API credentials, "
                    . 'their role (DecryptPassword) and WHMCS\'s API IP access, then run the import again.';

                return null;
            }
            report(new RuntimeException("Credential «{$what}» not decrypted: " . class_basename($e))); // its class only
            if (++$this->inARow >= self::IN_A_ROW) {
                $this->stopped = true;
                $this->notes[] = self::IN_A_ROW . ' failed in a row: WHMCS is not answering, the rest not asked. Run the import again.';
            }

            return null;
        }
        $this->inARow = 0;
        $value = $answer['password'] ?? null;
        if (!is_string($value)) {
            return null;
        }
        if ($value === '') {
            return ''; // decrypted, and empty: not a failure (a test mode left off)
        }
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            report(new RuntimeException("Credential «{$what}» not decrypted: not text")); // another key's ciphertext

            return null;
        }

        return $value;
    }

    /** WHMCS's own message, after WhmcsApi's prefix: its words about the call. */
    private function reason(Throwable $e): string
    {
        return mb_substr(trim((string) preg_replace('/^WHMCS API «[^»]*» failed \(HTTP \d+\): /u', '', $e->getMessage())), 0, 200);
    }

    /** @return list<array<string, mixed>> empty when the table is not there */
    private function rows(string $sql): array
    {
        try {
            return $this->whmcs->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return [];
        }
    }
}
