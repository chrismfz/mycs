<?php

namespace App\Services\Import;

use App\Models\DomainRegistrarConnection;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\Server;
use App\Models\Setting;
use App\Models\TicketDepartment;
use Closure;
use Illuminate\Support\Facades\DB;
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

    private int $sealedClosed = 0;

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
        $this->departments();
        $this->mail();
        // Each item's «not read» note stays (ADV3-561-1/2: a closing note says why a class of them failed, never which); these
        // say what the whole class needs.
        if ($this->needsKey) {
            $this->notes[] = 'Gateway settings in WHMCS\'s newer scheme need its cc_encryption_hash (WHMCS_CC_ENCRYPTION_HASH in the environment, '
                . 'from WHMCS\'s configuration.php; the same key brings the 2FA).';
        }
        if ($this->sealedClosed > 0) {
            $this->notes[] = "{$this->sealedClosed} gateway setting(s) are in WHMCS's newer scheme, which the cc_encryption_hash given did not open "
                . '(the 2FA key opens another part of that scheme; the gateways\' is not readable from outside WHMCS): enter the keys by hand, as the cut-over does.';
        }
        if ($this->needsApi) {
            $this->notes[] = "Some logins were not imported: WHMCS's API is needed to decrypt them (registrars, and gateways in its older scheme: "
                . '--whmcs-api-url, WHMCS_API_IDENTIFIER, WHMCS_API_SECRET in the environment).';
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

    // ---- support departments' mailboxes -----------------------------------------------------------------------------

    /** WHMCS reads a department's mailbox by POP3: the same mailbox is read here by IMAP (docs/tickets.md, owner 2026-10-27). */
    private const POP3_TO_IMAP = [995 => [993, 'ssl'], 110 => [143, 'starttls']];

    private function departments(): void
    {
        $mailboxes = [];
        foreach ($this->rows('SELECT id, name, host, port, login, password FROM tblticketdepartments ORDER BY id') as $row) {
            $host = trim((string) $row['host']);
            $login = trim((string) $row['login']);
            if ($host === '' && $login === '') {
                continue; // a department with no mailbox (it gets its tickets from the form or by hand)
            }
            $department = TicketDepartment::query()->where('whmcs_id', (int) $row['id'])->first();
            if ($department === null) {
                continue;
            }
            $this->counts['found']++;
            $label = "Department #{$row['id']} «{$row['name']}»";
            if ($department->hasMailbox()) {
                $this->skip("{$label}: a mailbox is already set here, kept.");

                continue;
            }
            if ($host === '' || $login === '' || trim((string) $row['password']) === '') {
                $this->skip("{$label}: WHMCS has no complete mailbox (host, login and password) for it.");

                continue;
            }
            $password = $this->plain((string) $row['password'], "department {$row['id']} mailbox password");
            if ($password === null || $password === '') {
                $this->skip("{$label}: its mailbox password was not read: enter it by hand.");

                continue;
            }
            [$port, $encryption] = self::POP3_TO_IMAP[(int) $row['port']] ?? [(int) $row['port'] ?: 993, (int) $row['port'] === 143 ? 'starttls' : 'ssl'];
            if ($port < 1 || $port > 65535) {
                $this->skip("{$label}: its mailbox port {$row['port']} is no port: enter the mailbox by hand.");

                continue;
            }
            // Two departments on one mailbox would both read it once polling is on: said, not decided.
            $mailbox = strtolower($host . '|' . $login);
            if (isset($mailboxes[$mailbox])) {
                $this->notes[] = "{$label} reads the same mailbox as {$mailboxes[$mailbox]}: turn polling on for one of them only.";
            }
            $mailboxes[$mailbox] = $label;
            // Polling stays off: it is opened per department after a test with a real mail (ROADMAP, tickets §6c).
            $department->forceFill(['imap_host' => $host, 'imap_port' => $port, 'imap_encryption' => $encryption, 'imap_username' => $login, 'imap_password' => $password])->saveQuietly();
            $this->counts['imported']++;
            $this->notes[] = "{$label}: WHMCS reads it by POP3 on port {$row['port']}; here it is read by IMAP on {$port} ({$encryption}). Check the host offers IMAP, "
                . 'then turn its polling on after a test (Departments).';
            unset($password);
        }
    }

    // ---- the central mail ---------------------------------------------------------------------------------------------

    /** MailConfig's encryption => this app's `mail_encryption` (null: none). */
    private const MAIL_ENCRYPTION = ['tls' => 'tls', 'ssl' => 'ssl', '' => null, 'none' => null];

    /** The SMTP server WHMCS sends its mails through (invoices, orders, reminders…): a login, and where the mails come from. */
    private function mail(): void
    {
        $config = [];
        foreach ($this->rows("SELECT setting, value FROM tblconfiguration WHERE setting IN ('MailType', 'MailConfig', 'SystemEmailsFromEmail', 'SystemEmailsFromName')") as $row) {
            $config[$row['setting']] = (string) $row['value'];
        }
        if (trim($config['MailConfig'] ?? '') === '') {
            if (strtolower(trim($config['MailType'] ?? '')) === 'smtp') {
                $this->notes[] = 'Central mail: WHMCS sends by SMTP but keeps no MailConfig (an older install): set the mail server by hand.';
            }

            return;
        }
        $this->counts['found']++;
        if (strtolower(trim($config['MailType'] ?? '')) !== 'smtp') {
            $this->skip("Central mail: WHMCS sends with «{$config['MailType']}», not SMTP: set the mail server by hand.");

            return;
        }
        if (filled(Setting::query()->where('key', 'mail_host')->whereNull('settingable_type')->value('value'))) {
            $this->skip('Central mail: a mail server is already set here, kept.');

            return;
        }
        $json = $this->plain($config['MailConfig'], 'central mail configuration');
        $decoded = $json === null || $json === '' ? null : json_decode($json, true);
        $smtp = is_array($decoded) && is_array($decoded['configuration'] ?? null) ? $decoded['configuration'] : null;
        unset($json, $decoded);
        if ($smtp === null) {
            $this->skip('Central mail: its configuration was not read: set the mail server by hand.');

            return;
        }
        $host = trim((string) ($smtp['host'] ?? ''));
        $port = (int) ($smtp['port'] ?? 0);
        $authType = strtolower(trim((string) ($smtp['auth_type'] ?? 'plain')));
        $secure = strtolower(trim((string) ($smtp['secure'] ?? '')));
        $username = trim((string) ($smtp['username'] ?? ''));
        $password = (string) ($smtp['password'] ?? '');
        // Only what this app speaks: a login and password over SMTP, with TLS, SSL or none. OAuth2 and the rest are for staff.
        if ($host === '' || $port < 1 || $port > 65535 || !array_key_exists($secure, self::MAIL_ENCRYPTION) || !in_array($authType, ['plain', 'login', ''], true)
            || ($username === '') !== ($password === '')) {
            $this->skip('Central mail: its SMTP settings are not a host, a port and a plain login this app takes (OAuth2, or incomplete): set the mail server by hand.');

            return;
        }
        $values = [
            'mail_host' => [$host, false], 'mail_port' => [(string) $port, false], 'mail_username' => [$username, false],
            'mail_password' => [$password, true], 'mail_encryption' => [self::MAIL_ENCRYPTION[$secure] ?? '', false],
        ];
        $from = trim($config['SystemEmailsFromEmail'] ?? '');
        if (filter_var($from, FILTER_VALIDATE_EMAIL) !== false) {
            $values['mail_from_address'] = [$from, false];
        }
        if (trim($config['SystemEmailsFromName'] ?? '') !== '') {
            $values['mail_from_name'] = [trim($config['SystemEmailsFromName']), false];
        }
        // All or none: a host with no password would read, on a re-run, as a mail server already set.
        DB::transaction(function () use ($values) {
            foreach ($values as $key => [$value, $encrypted]) {
                Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null, 'settingable_id' => null], ['value' => $value, 'type' => 'string', 'encrypted' => $encrypted]);
            }
        });
        $this->counts['imported']++;
        $this->notes[] = 'Central mail: WHMCS\'s SMTP server and sender came (the Mail Encryption setting does not make Laravel require TLS: it uses 465 as SSL and otherwise STARTTLS when offered; test it before the cut-over). The header, footer and CSS of its emails (Smarty) do not: this app has its own. '
            . 'Nothing sends from a copy unless its queue runs: do not run one on this database.';
        unset($password, $smtp);
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
                $this->sealedClosed++;

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
