<?php

namespace App\Services\Import;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\InvoiceItem;
use App\Services\Domains\DomainRegistrarRegistry;
use App\Services\Domains\DomainReminders;
use App\Services\Whmcs\CatalogImport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Throwable;

/**
 * WHMCS's domains (D6, docs/domains.md, docs/importer.md «Domains»), for the full importer after its customers, orders
 * and services, before the invoice lines (which then point at their domains). Read-only on WHMCS.
 *
 * - A registrar connection per WHMCS registrar module that has a driver here, **without** credentials and off (staff
 *   enter them); the TLDs and their prices as the catalog brings them, each on its WHMCS module's connection.
 * - Each domain with its WHMCS id, its customer's currency, dates, recurring amount, addons and «do not renew»; its
 *   registrant and admin the customer's primary contact (as domains:adopt). An Openprovider handle from WHMCS is the
 *   role's only when its WHMCS contact has that contact's email (D4b: never our contact paired with another person
 *   there); every other one is kept in `meta.whmcs_handles` for staff. Additional fields in `meta.whmcs_fields`.
 * - Its registrar as WHMCS has it. WHMCS's CNIC → Openprovider hook log (mod_domain_migration) is kept in its meta for
 *   staff, never trusted over it: the 26 attempts it logged as failed were all at Openprovider by 2026-10-29 (moved by
 *   hand since; the registrars' own lists, read then). The first sync with credentials confirms each domain where it is
 *   (importer.md, cut-over).
 * - The expiry notices WHMCS sent for each domain's current expiry, so none goes again (as #531's for invoices).
 * - Its invoice lines (line()): a renewal with its historical period, applied when paid (the year was renewed at the
 *   registrar already); a registration or transfer as such; anything else a charge.
 */
final class WhmcsDomainImport
{
    /** WHMCS's domain status → here; a pending one by its type (register or transfer). */
    private const STATUSES = [
        'active' => DomainStatus::Active, 'expired' => DomainStatus::Expired, 'grace' => DomainStatus::Grace,
        'redemption' => DomainStatus::Redemption, 'transferred away' => DomainStatus::TransferredAway,
        'cancelled' => DomainStatus::Cancelled, 'fraud' => DomainStatus::Cancelled,
    ];

    /** WHMCS's date formats (DateFormat) → PHP's. */
    private const DATE_FORMATS = ['DD/MM/YYYY' => 'd/m/Y', 'DD.MM.YYYY' => 'd.m.Y', 'DD-MM-YYYY' => 'd-m-Y', 'MM/DD/YYYY' => 'm/d/Y',
        'YYYY/MM/DD' => 'Y/m/d', 'YYYY-MM-DD' => 'Y-m-d'];

    /** @var array<string, int> */
    private array $counts = ['connections' => 0, 'tlds' => 0, 'domains' => 0, 'skipped' => 0, 'handles' => 0, 'handles_kept' => 0, 'reminders' => 0];

    /** @var list<string> */
    private array $notes = [];

    /** @var array<string, DomainRegistrarConnection> WHMCS registrar module => its connection here */
    private array $connections = [];

    /** @var array<int, array{expires: ?string, years: int}>|null the domains imported, by id */
    private ?array $imported = null;

    private ?string $dateFormat = null;

    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        if (!$this->has('tbldomains')) {
            return ['counts' => $this->counts, 'notes' => $this->notes];
        }
        $this->connections();
        $catalog = (new CatalogImport($this->whmcs, makeDefaultCurrency: false))->tldsForFullImport($this->connections);
        $this->counts['tlds'] = $catalog['counts']['tlds'];
        array_push($this->notes, ...$catalog['notes']);
        $this->domains();
        $this->reminders();

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /**
     * A WHMCS invoice line of a domain imported: what it is here, or null (not a domain's, or its domain is not here).
     *
     * @param  array<string, mixed>  $record  a tblinvoiceitems row, with its invoice's status and datepaid
     * @return array{reference_type: string, reference_id: int, kind: string|null, period_start: string|null, period_end: string|null, applied_at: string|null}|null
     */
    public function line(array $record): ?array
    {
        $type = (string) ($record['type'] ?? '');
        if (!str_starts_with($type, 'Domain') || !isset($this->imported()[(int) $record['relid']])) {
            return null;
        }
        $domain = $this->imported()[(int) $record['relid']];
        // Paid (or refunded since): WHMCS did it at the registrar; never done again here (importer.md «Γραμμές domain»).
        $done = in_array((string) ($record['invoice_status'] ?? ''), ['Paid', 'Refunded'], true);
        $applied = $done ? ($this->date($record['invoice_datepaid'] ?? null) ?? now()->toDateTimeString()) : null;
        $line = ['reference_type' => Domain::class, 'reference_id' => (int) $record['relid'], 'kind' => InvoiceItem::KIND_CHARGE,
            'period_start' => null, 'period_end' => null, 'applied_at' => null];

        return match ($type) {
            'Domain' => ['kind' => null, 'applied_at' => $applied, ...$this->period($record, $domain['years'])] + $line,
            'DomainRegister' => ['kind' => InvoiceItem::KIND_REGISTER, 'applied_at' => $applied] + $line,
            'DomainTransfer' => ['kind' => InvoiceItem::KIND_TRANSFER, 'applied_at' => $applied] + $line,
            default => $line, // a redemption fee, an addon (ID protection, DNS): a charge, never a period
        };
    }

    // ---- connections ------------------------------------------------------------------------------------------------

    private function connections(): void
    {
        $registry = app(DomainRegistrarRegistry::class);
        $used = array_map(fn ($value) => strtolower(trim((string) $value)), $this->column('SELECT DISTINCT registrar FROM tbldomains'));
        if ($this->has('tbldomainpricing')) {
            $used = [...$used, ...array_map(fn ($value) => strtolower(trim((string) $value)), $this->column('SELECT DISTINCT autoreg FROM tbldomainpricing'))];
        }
        foreach (array_unique(array_filter($used)) as $module) {
            if (!in_array($module, $registry->keys(), true)) {
                $this->notes[] = "Registrar «{$module}» in WHMCS has no driver here: its domains come without a connection (by hand).";

                continue;
            }
            $this->connections[$module] = DomainRegistrarConnection::query()->where('registrar', $module)->orderBy('id')->first()
                ?? DomainRegistrarConnection::query()->create(['registrar' => $module, 'label' => $registry->label($module) . ' (from WHMCS)',
                    'is_active' => false, 'mode' => 'off']);
            $this->counts['connections']++;
        }
        // WHMCS's CNIC → Openprovider hook, here a setting of the connection (domains.md, step 4; owner 2026-10-22: «they all
        // go to Openprovider»): a paid renewal at CNIC is a transfer to Openprovider, only once that one accepts it.
        if (isset($this->connections['cnic'], $this->connections['openprovider']) && $this->connections['cnic']->renew_transfer_to_id === null) {
            $this->connections['cnic']->forceFill(['renew_transfer_to_id' => $this->connections['openprovider']->id])->save();
            $this->notes[] = 'CNIC: a paid renewal is a transfer to Openprovider (as WHMCS\'s migration hook), once both connections are set up.';
        }
        if ($this->connections !== []) {
            $this->notes[] = 'Registrar connections ' . implode(', ', array_keys($this->connections))
                . ': off and without credentials until staff enter them (Domains → Registrars).';
        }
    }

    // ---- domains ----------------------------------------------------------------------------------------------------

    private function domains(): void
    {
        $clients = DB::table('clients')->pluck('id')->flip()->all();
        $orders = DB::table('orders')->pluck('id')->flip()->all();
        $tlds = DomainTld::query()->pluck('id', 'tld')->all();
        $primary = DB::table('contacts')->whereNotNull('primary_of_client_id')->get(['id', 'primary_of_client_id', 'email'])->keyBy('primary_of_client_id');
        $currencies = $this->pairs('SELECT c.id, cur.code FROM tblclients c JOIN tblcurrencies cur ON cur.id = c.currency');
        $default = (string) config('settings.default_currency', 'USD');
        $fields = $this->grouped('tbldomainsadditionalfields', 'SELECT domainid AS domain, name, value FROM tbldomainsadditionalfields ORDER BY id');
        $extra = $this->grouped('tbldomains_extra', 'SELECT domain_id AS domain, name, value FROM tbldomains_extra ORDER BY id');
        $handles = $this->handles();
        $migrations = $this->migrations();
        $live = [];
        $rows = [];
        foreach ($this->rows('SELECT * FROM tbldomains ORDER BY id') as $row) {
            $id = (int) $row['id'];
            if (!isset($clients[(int) $row['userid']])) {
                $this->counts['skipped']++;
                $this->notes[] = "Domain #{$id}: its customer #{$row['userid']} is not here, skipped.";

                continue;
            }
            try {
                $fqdn = Domain::normalise((string) $row['domain']);
            } catch (Throwable) {
                $this->counts['skipped']++;
                $this->notes[] = "Domain #{$id}: «{$row['domain']}» is not a domain name, skipped.";

                continue;
            }
            $tld = $this->tldOf($fqdn, $tlds);
            $raw = strtolower(trim((string) $row['status']));
            $status = self::STATUSES[$raw] ?? (str_starts_with($raw, 'pending')
                ? (stripos((string) $row['type'], 'transfer') !== false || $raw === 'pending transfer' ? DomainStatus::PendingTransfer : DomainStatus::PendingRegister)
                : null);
            if ($status === null) {
                $status = DomainStatus::Cancelled;
                $this->notes[] = "Domain #{$id} {$fqdn}: WHMCS status «{$row['status']}» unknown, imported cancelled: check it.";
            }
            $ended = in_array($status, [DomainStatus::TransferredAway, DomainStatus::Cancelled, DomainStatus::Deleted], true);
            if (!$ended && isset($live[$fqdn])) {
                // One live row per name here (live_fqdn): a second one in WHMCS comes in cancelled, listed.
                $this->notes[] = "Domain #{$id} {$fqdn}: also live as #{$live[$fqdn]} in WHMCS, imported cancelled: check them.";
                $status = DomainStatus::Cancelled;
                $ended = true;
            }
            if (!$ended) {
                $live[$fqdn] = $id;
            }
            $contact = $primary[(int) $row['userid']] ?? null;
            [$roleHandles, $kept] = $this->rolesHandles($handles[$id] ?? [], $contact?->email);
            $fieldsOf = $fields[$id] ?? [];
            $extraOf = $extra[$id] ?? [];
            $idn = trim((string) ($extraOf['idnLanguage'] ?? $fieldsOf['Internationalized domain name Script (only for IDN domains)'] ?? ''));
            $meta = array_filter([
                'whmcs' => array_filter(['registrar' => trim((string) $row['registrar']), 'status' => (string) $row['status'], 'type' => (string) $row['type'],
                    'next_due' => $this->date($row['nextduedate'] ?? null, dateOnly: true)]),
                'whmcs_fields' => $fieldsOf,
                'whmcs_handles' => $kept,
            ]);
            $years = max(1, min(10, (int) ($row['registrationperiod'] ?? 1)));
            $registrar = strtolower(trim((string) $row['registrar']));
            $migration = $migrations[$id] ?? null;
            if ($migration !== null) {
                $meta['whmcs_migration'] = $migration;
            }
            $rows[] = [
                'id' => $id,
                'client_id' => (int) $row['userid'],
                'order_id' => isset($orders[(int) ($row['orderid'] ?? 0)]) ? (int) $row['orderid'] : null,
                'domain_tld_id' => $tlds[$tld] ?? null,
                'registrar_connection_id' => isset($this->connections[$registrar]) ? $this->connections[$registrar]->id : null,
                'sld' => substr($fqdn, 0, -strlen($tld) - 1),
                'tld' => $tld,
                'fqdn' => $fqdn,
                'status' => $status->value,
                'registered_at' => $this->date($row['registrationdate'] ?? null, dateOnly: true),
                'expires_at' => $this->date($row['expirydate'] ?? null, dateOnly: true),
                'years' => $years,
                'currency_code' => $currencies[(int) $row['userid']] ?? $default,
                'recurring_amount' => number_format((float) ($row['recurringamount'] ?? 0), 2, '.', ''),
                'auto_renew' => !(int) ($row['donotrenew'] ?? 0),
                'id_protection' => (bool) (int) ($row['idprotection'] ?? 0),
                'dns_management' => (bool) (int) ($row['dnsmanagement'] ?? 0),
                'email_forwarding' => (bool) (int) ($row['emailforwarding'] ?? 0),
                'dnssec_enabled' => in_array(strtolower(trim((string) ($extraOf['openprovider_dnssecmanagement'] ?? ''))), ['on', '1', 'yes', 'true'], true),
                'idn_script' => $idn !== '' ? mb_substr($idn, 0, 30) : null,
                'registrant_contact_id' => $contact?->id,
                'admin_contact_id' => $contact?->id,
                'registrant_handle' => $roleHandles['registrant'] ?? null,
                'admin_handle' => $roleHandles['admin'] ?? null,
                'notes' => trim((string) ($row['additionalnotes'] ?? '')) ?: null,
                'meta' => $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => $this->date($row['created_at'] ?? null) ?? $this->date($row['registrationdate'] ?? null) ?? now()->toDateTimeString(),
                'updated_at' => $this->date($row['updated_at'] ?? null) ?? now()->toDateTimeString(),
            ];
            $this->counts['domains']++;
            $this->counts['handles'] += count($roleHandles);
            $this->counts['handles_kept'] += count($kept);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('domains')->insert($chunk);
        }
        $this->imported = null;
    }

    /**
     * Each domain's last real attempt of WHMCS's registrar migration hook (mod_domain_migration, not a dry run): its
     * outcome, from and to which registrar, when. Never its EPP code.
     *
     * @return array<int, array{status: string, old: string, new: string, at: string|null}>
     */
    private function migrations(): array
    {
        if (!$this->has('mod_domain_migration')) {
            return [];
        }
        $migrations = [];
        foreach ($this->rows('SELECT domain_id, status, old_registrar, new_registrar, created_at FROM mod_domain_migration WHERE dry_run = 0 ORDER BY id') as $row) {
            $migrations[(int) $row['domain_id']] = ['status' => strtolower(trim((string) $row['status'])), 'old' => strtolower(trim((string) $row['old_registrar'])),
                'new' => strtolower(trim((string) $row['new_registrar'])), 'at' => $this->date($row['created_at'] ?? null)];
        }

        return $migrations;
    }

    /** Its TLD here (the longest one known: «com.gr» before «gr»), else what follows its first label. */
    private function tldOf(string $fqdn, array $tlds): string
    {
        $labels = explode('.', $fqdn);
        for ($i = 1; $i < count($labels); $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            if (isset($tlds[$candidate])) {
                return $candidate;
            }
        }

        return implode('.', array_slice($labels, 1));
    }

    /**
     * Each domain's Openprovider handles in WHMCS (wDomain_handle → wHandles), with the email of the contact each is.
     * Read from the module's object for its email only: nothing else of it (an ID number, a birth date) is kept.
     *
     * @return array<int, array<string, array{handle: string, email: ?string}>> domain id => role => its handle
     */
    private function handles(): array
    {
        if (!$this->has('wDomain_handle') || !$this->has('wHandles')) {
            return [];
        }
        $handles = [];
        foreach ($this->rows('SELECT dh.domain_id, dh.type, h.handle, h.data FROM wDomain_handle dh JOIN wHandles h ON h.id = dh.handle_id ORDER BY dh.id') as $row) {
            $role = strtolower(trim((string) $row['type']));
            $handle = trim((string) $row['handle']);
            if (!in_array($role, Domain::ROLES, true) || $handle === '') {
                continue;
            }
            $handles[(int) $row['domain_id']][$role] = ['handle' => mb_substr($handle, 0, 60), 'email' => self::emailOf((string) $row['data'])];
        }

        return $handles;
    }

    /** The «email» of a serialized Openprovider customer (an object of a class not loaded here: read as an array). */
    private static function emailOf(string $data): ?string
    {
        if ($data === '') {
            return null;
        }
        $object = @unserialize($data, ['allowed_classes' => false]);
        if (!is_object($object) && !is_array($object)) {
            return null;
        }
        foreach ((array) $object as $key => $value) {
            if (preg_replace('/^\0.*\0/', '', (string) $key) === 'email' && is_string($value)) {
                return mb_strtolower(trim($value)) ?: null;
            }
        }

        return null;
    }

    /**
     * The registrant's and admin's handles from WHMCS that are the account's primary contact (its email: D4b), and the
     * rest kept for staff (another person there, or a role with no contact here: tech, billing).
     *
     * @param  array<string, array{handle: string, email: ?string}>  $handles
     * @return array{0: array<string, string>, 1: array<string, string>} [role => handle linked, role => handle kept]
     */
    private function rolesHandles(array $handles, ?string $email): array
    {
        $email = $email !== null ? mb_strtolower(trim($email)) : '';
        $linked = [];
        $kept = [];
        foreach ($handles as $role => $handle) {
            if (in_array($role, ['registrant', 'admin'], true) && $email !== '' && $handle['email'] === $email) {
                $linked[$role] = $handle['handle'];
            } else {
                $kept[$role] = $handle['handle'];
            }
        }

        return [$linked, $kept];
    }

    // ---- reminders --------------------------------------------------------------------------------------------------

    /**
     * The notices WHMCS sent for each domain's current expiry (tbldomainreminders: the day sent, how many days before),
     * as sent here; and every notice day here at or before the closest one WHMCS sent, so the customer never gets an
     * earlier one again («30 days» after WHMCS's «10 days»). An older expiry's notices are history, not needed.
     */
    private function reminders(): void
    {
        if (!$this->has('tbldomainreminders')) {
            return;
        }
        $ours = array_values(array_filter(DomainReminders::days(), fn (int $day) => $day >= 0));
        $sent = [];
        foreach ($this->rows('SELECT domain_id, date, days_before_expiry FROM tbldomainreminders ORDER BY id') as $row) {
            $domain = $this->imported()[(int) $row['domain_id']] ?? null;
            $date = $this->date($row['date'] ?? null, dateOnly: true);
            $days = (int) $row['days_before_expiry'];
            if ($domain === null || $domain['expires'] === null || $date === null || $days < 0) {
                continue;
            }
            $for = Carbon::parse($date)->addDays($days);
            if (abs($for->diffInDays(Carbon::parse($domain['expires']), false)) > DomainReminders::SAME_EXPIRY_DAYS) {
                continue;
            }
            $sent[(int) $row['domain_id']][$days] = $date;
        }
        $rows = [];
        foreach ($sent as $domainId => $days) {
            $expires = $this->imported()[$domainId]['expires'];
            $closest = min(array_keys($days));
            foreach ($days + array_fill_keys(array_filter($ours, fn (int $day) => $day >= $closest), $days[$closest]) as $day => $date) {
                $rows[] = ['domain_id' => $domainId, 'days' => $day, 'expires_on' => $expires, 'sent_at' => $date . ' 00:00:00',
                    'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->counts['reminders'] += DB::table('domain_reminders')->insertOrIgnore($chunk);
        }
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    /**
     * A renewal line's period: its description's dates (WHMCS «… (21/11/2025 - 20/11/2026)», in its DateFormat), from
     * their start for the years it says (the end is the next expiry here, a day after WHMCS's); else from its due date
     * for the domain's years. Neither: none (then a charge would be safer; a renewal without a period is never made).
     *
     * @return array{period_start: string|null, period_end: string|null, kind?: string}
     */
    private function period(array $record, int $years): array
    {
        $description = (string) ($record['description'] ?? '');
        if (preg_match('/(\d+)\s*(?:Year|Έτ)/u', $description, $count)) {
            $years = max(1, min(10, (int) $count[1]));
        }
        $start = null;
        if (preg_match('#\((\d{1,4}[./-]\d{1,2}[./-]\d{1,4})\s*-\s*\d{1,4}[./-]\d{1,2}[./-]\d{1,4}\)#', $description, $dates)) {
            $parsed = \DateTime::createFromFormat('!' . $this->phpDateFormat(), $dates[1]);
            $start = $parsed !== false ? Carbon::instance($parsed) : null;
        }
        $start ??= ($due = $this->date($record['duedate'] ?? null, dateOnly: true)) !== null ? Carbon::parse($due) : null;
        if ($start === null) {
            return ['period_start' => null, 'period_end' => null, 'kind' => InvoiceItem::KIND_CHARGE];
        }

        return ['period_start' => $start->toDateString(), 'period_end' => $start->copy()->addYearsNoOverflow($years)->toDateString()];
    }

    private function phpDateFormat(): string
    {
        if ($this->dateFormat === null) {
            $setting = $this->has('tblconfiguration')
                ? (string) ($this->whmcs->query("SELECT value FROM tblconfiguration WHERE setting = 'DateFormat'")->fetchColumn() ?: '') : '';
            $this->dateFormat = self::DATE_FORMATS[strtoupper(trim($setting))] ?? 'd/m/Y';
        }

        return $this->dateFormat;
    }

    /** @return array<int, array{expires: ?string, years: int}> */
    private function imported(): array
    {
        return $this->imported ??= DB::table('domains')->get(['id', 'expires_at', 'years'])
            ->mapWithKeys(fn ($domain) => [(int) $domain->id => ['expires' => $domain->expires_at !== null ? substr((string) $domain->expires_at, 0, 10) : null,
                'years' => max(1, (int) $domain->years)]])->all();
    }

    private function date(mixed $value, bool $dateOnly = false): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            $date = Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
        if ($date->year < 1900 || $date->year > 2100) {
            return null;
        }

        return $dateOnly ? $date->toDateString() : $date->toDateTimeString();
    }

    private function has(string $table): bool
    {
        try {
            $this->whmcs->query("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        return $this->whmcs->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<mixed> */
    private function column(string $sql): array
    {
        return $this->whmcs->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<int, string> */
    private function pairs(string $sql): array
    {
        try {
            return array_map('strval', $this->whmcs->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (PDOException) {
            return [];
        }
    }

    /** @return array<int, array<string, string>> domain id => name => value (a later row of a name wins) */
    private function grouped(string $table, string $sql): array
    {
        if (!$this->has($table)) {
            return [];
        }
        $grouped = [];
        foreach ($this->rows($sql) as $row) {
            $value = trim((string) $row['value']);
            if ($value !== '') {
                $grouped[(int) $row['domain']][(string) $row['name']] = mb_substr($value, 0, 500);
            }
        }

        return $grouped;
    }
}
