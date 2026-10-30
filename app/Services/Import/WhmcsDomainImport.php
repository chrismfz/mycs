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
use Illuminate\Support\Facades\Crypt;
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

    /** A paid renewal is done when WHMCS's expiry reaches its end within this many days (an expiry a sync moves a little). */
    private const COVERED_WITHIN_DAYS = 31;

    /** WHMCS's date formats (DateFormat) → PHP's. */
    private const DATE_FORMATS = ['DD/MM/YYYY' => 'd/m/Y', 'DD.MM.YYYY' => 'd.m.Y', 'DD-MM-YYYY' => 'd-m-Y', 'MM/DD/YYYY' => 'm/d/Y',
        'YYYY/MM/DD' => 'Y/m/d', 'YYYY-MM-DD' => 'Y-m-d'];

    /** @var array<string, int> */
    private array $counts = ['connections' => 0, 'tlds' => 0, 'domains' => 0, 'skipped' => 0, 'handles' => 0, 'handles_kept' => 0, 'reminders' => 0];

    /** @var list<string> */
    private array $notes = [];

    /** @var list<string> what line() found, said after the invoice lines (run() has reported by then) */
    private array $lineNotes = [];

    /** @return list<string> */
    public function lineNotes(): array
    {
        return $this->lineNotes;
    }

    /** @var list<array{line: int, domain: int, years: int, invoice: int, at: string|null}> years WHMCS renewed of a line left to be applied */
    private array $doneYears = [];

    /**
     * After the invoice lines: the years WHMCS already renewed of a paid line left to be applied, as an «ok» renewal of
     * that line in the domain's registrar log (what DomainRenewalService::chargedYears counts), so it buys only the rest.
     */
    public function recordDoneYears(): void
    {
        foreach ($this->doneYears as $done) {
            DB::table('domain_registrar_logs')->insert(['domain_id' => $done['domain'],
                'registrar_connection_id' => DB::table('domains')->where('id', $done['domain'])->value('registrar_connection_id'),
                'action' => 'renew', 'status' => 'ok', 'invoice_id' => $done['invoice'] ?: null,
                'request' => json_encode(['line' => $done['line'], 'years' => $done['years'], 'source' => 'whmcs']),
                'response' => json_encode(['note' => 'Renewed in WHMCS before the import (its expiry shows these years of the line).']),
                'created_at' => $done['at'] ?? now(), 'updated_at' => $done['at'] ?? now()]);
        }
    }

    /** @var array<string, DomainRegistrarConnection> WHMCS registrar module => its connection here */
    private array $connections = [];

    /** @var array<int, array{expires: ?string, years: int, ended: bool, pending: bool}>|null the domains imported, by id */

    /** @var array<int, list<string>>|null each domain's paid or open WHMCS renewal lines, by their period's start */
    private ?array $standing = null;

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
     * @return array{reference_type: string, reference_id: int, kind: string|null, period_start: string|null, period_end: string|null, applied_at: string|null, forgone_at: string|null}|null
     */
    public function line(array $record): ?array
    {
        $type = (string) ($record['type'] ?? '');
        if (!str_starts_with($type, 'Domain') || !isset($this->imported()[(int) $record['relid']])) {
            return null;
        }
        $domain = $this->imported()[(int) $record['relid']];
        $status = (string) ($record['invoice_status'] ?? '');
        // Paid (or refunded since): WHMCS did it at the registrar; never done again here (importer.md «Γραμμές domain»).
        $done = in_array($status, ['Paid', 'Refunded'], true);
        $applied = $done ? ($this->date($record['invoice_datepaid'] ?? null) ?? now()->toDateTimeString()) : null;
        $line = ['reference_type' => Domain::class, 'reference_id' => (int) $record['relid'], 'kind' => InvoiceItem::KIND_CHARGE,
            'period_start' => null, 'period_end' => null, 'applied_at' => null, 'forgone_at' => null];
        if ($type === 'Domain') {
            $renewal = ['kind' => null, ...$this->period($record, $domain['years'])] + $line;
            // Paid, but WHMCS's expiry never reached its end (its renewal at the registrar not done, or a transfer that
            // added one year of two: reviews of #535): left to be applied. The renewal reads the registrar first, adopts
            // what it has and buys only the years it lacks; listed for staff. An ended domain's is history: applied.
            $short = $done && $renewal['period_end'] !== null && !$domain['ended']
                && ($domain['expires'] === null || Carbon::parse($domain['expires'])->addDays(self::COVERED_WITHIN_DAYS)->lessThan(Carbon::parse($renewal['period_end'])));
            if ($short) {
                // The years of it WHMCS's expiry already shows (the hook's transfer added one of two: R2-2 of #535), as
                // charged for this line: the renewal buys only the rest, never the whole again.
                $paidYears = max(1, (int) round(Carbon::parse($renewal['period_start'])->diffInMonths(Carbon::parse($renewal['period_end'])) / 12));
                $done = 0;
                while ($domain['expires'] !== null && $done < $paidYears
                    && Carbon::parse($renewal['period_start'])->addYearsNoOverflow($done + 1)->lessThanOrEqualTo(Carbon::parse($domain['expires'])->addDays(self::COVERED_WITHIN_DAYS))) {
                    $done++;
                }
                if ($done >= $paidYears) {
                    $short = false; // its description ran a little past its whole years: done (round 3 of #535)
                } elseif ($done > 0) {
                    $this->doneYears[] = ['line' => (int) $record['id'], 'domain' => (int) $record['relid'], 'years' => $done, 'invoice' => (int) ($record['invoiceid'] ?? 0),
                        'at' => $applied];
                }
                if ($short) {
                    $this->lineNotes[] = "Domain #{$record['relid']}: invoice #" . ($record['invoiceid'] ?? '?') . " paid its renewal to {$renewal['period_end']}"
                        . " ({$paidYears} year(s)), WHMCS's expiry is " . ($domain['expires'] ?? 'none') . ($done > 0 ? " ({$done} done)" : '')
                        . ': left to be renewed (the registrar read first: only the years it lacks).';
                }
            }
            // Cancelled in WHMCS for the period now due and not renewed (its expiry still at its start): given up, as a
            // cancellation here does (Δ3a). Never an alternative not taken (a longer one, the domain renewed by another):
            // given up, it would count as billed and stop the next renewal (R2-1 of #535).
            // Nor one beside a paid or open renewal of the same period: that one stands for it (R3-1 of #535).
            $forgone = $status === 'Cancelled' && $renewal['period_start'] !== null && $renewal['period_start'] <= now()->toDateString()
                && !$domain['ended'] && $domain['expires'] !== null
                && abs(Carbon::parse($domain['expires'])->diffInDays(Carbon::parse($renewal['period_start']), false)) <= self::COVERED_WITHIN_DAYS
                && !$this->standsFor((int) $record['relid'], $renewal['period_start']);

            return ['applied_at' => $short ? null : $applied, 'forgone_at' => $forgone ? now()->toDateTimeString() : null] + $renewal;
        }

        // Paid, but the domain still pending (never registered, or its transfer not done): not done, so the order is sent
        // to its registrar here (DomainOrderFulfilment), listed (R3-2 of #535).
        $order = in_array($type, ['DomainRegister', 'DomainTransfer'], true);
        if ($order && $done && $domain['pending']) {
            $applied = null;
            $this->lineNotes[] = "Domain #{$record['relid']}: its order paid on invoice #" . ($record['invoiceid'] ?? '?') . ', still pending in WHMCS: '
                . 'sent to its registrar here once its connection is set up.';
        }

        return match ($type) {
            'DomainRegister' => ['kind' => InvoiceItem::KIND_REGISTER, 'applied_at' => $applied] + $line,
            'DomainTransfer' => ['kind' => InvoiceItem::KIND_TRANSFER, 'applied_at' => $applied] + $line,
            // A stage's fee as the cron bills it (D5b-1), so it is never added a second time to the same invoice.
            'DomainRedemptionFee' => ['kind' => 'redemption_fee'] + $line,
            'DomainGraceFee' => ['kind' => 'grace_fee'] + $line,
            default => $line, // an addon (ID protection, DNS, email forwarding): a charge, never a period
        };
    }

    /** A paid or open renewal of this domain starts (within the covered days) where this one does. */
    private function standsFor(int $domain, string $start): bool
    {
        if ($this->standing === null) {
            $this->standing = [];
            if ($this->has('tblinvoiceitems') && $this->has('tblinvoices')) {
                foreach ($this->rows("SELECT ii.relid, ii.description, ii.duedate FROM tblinvoiceitems ii JOIN tblinvoices i ON i.id = ii.invoiceid
                    WHERE ii.type = 'Domain' AND i.status IN ('Paid', 'Unpaid', 'Payment Pending', 'Refunded')") as $row) {
                    $period = $this->period($row, $this->imported()[(int) $row['relid']]['years'] ?? 1);
                    if ($period['period_start'] !== null) {
                        $this->standing[(int) $row['relid']][] = $period['period_start'];
                    }
                }
            }
        }
        foreach ($this->standing[$domain] ?? [] as $other) {
            if (abs(Carbon::parse($other)->diffInDays(Carbon::parse($start), false)) <= self::COVERED_WITHIN_DAYS) {
                return true;
            }
        }

        return false;
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
                $this->notes[] = "Registrar «{$module}» in WHMCS has no driver here: its domains come on the manual connection (by hand).";

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

    private ?DomainRegistrarConnection $manual = null;

    /** The connection of domains at a registrar with no driver here (enom), or none: manual, staff renew them by hand. */
    private function manual(): DomainRegistrarConnection
    {
        return $this->manual ??= DomainRegistrarConnection::query()->where('registrar', DomainRegistrarConnection::MANUAL)->orderBy('id')->first()
            ?? DomainRegistrarConnection::query()->create(['registrar' => DomainRegistrarConnection::MANUAL, 'label' => 'Manual (from WHMCS)',
                'is_active' => false, 'mode' => 'off']);
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
        $paidRenewals = $this->has('tblinvoiceitems') && $this->has('tblinvoices') ? array_flip(array_map('intval', $this->column("SELECT DISTINCT ii.relid FROM tblinvoiceitems ii
            JOIN tblinvoices i ON i.id = ii.invoiceid WHERE ii.type = 'Domain' AND i.status = 'Paid'"))) : [];
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
            // Still «Pending» in WHMCS, but registered (an order has no expiry until it is) or renewed and paid there: live,
            // never an order the cron cancels as unpaid (review of #535).
            $expires = $this->date($row['expirydate'] ?? null, dateOnly: true);
            // Not a pending transfer carrying the losing registrar's expiry (R2-3 of #535): renewed here, or registered over a
            // year ago, and its expiry still ahead.
            $registered = $this->date($row['registrationdate'] ?? null, dateOnly: true);
            if (in_array($status, [DomainStatus::PendingRegister, DomainStatus::PendingTransfer], true)
                && $expires !== null && $expires >= now()->toDateString()
                && (isset($paidRenewals[$id]) || ($registered !== null && $registered <= now()->subYear()->toDateString()))) {
                $status = DomainStatus::Active;
                $this->notes[] = "Domain #{$id} {$fqdn}: «Pending» in WHMCS with an expiry ({$expires}): imported active, check it.";
            }
            $ended = in_array($status, [DomainStatus::TransferredAway, DomainStatus::Cancelled, DomainStatus::Deleted], true);
            if (!$ended) {
                $live[$fqdn][] = count($rows);
            }
            $contact = $primary[(int) $row['userid']] ?? null;
            [$roleHandles, $kept] = $this->rolesHandles($handles[$id] ?? [], $contact?->email);
            $fieldsOf = $fields[$id] ?? [];
            $extraOf = $extra[$id] ?? [];
            $idn = trim((string) ($extraOf['idnLanguage'] ?? $fieldsOf['Internationalized domain name Script (only for IDN domains)'] ?? ''));
            $meta = array_filter([
                'whmcs' => array_filter(['registrar' => trim((string) $row['registrar']), 'status' => (string) $row['status'], 'type' => (string) $row['type'],
                    'next_due' => $this->date($row['nextduedate'] ?? null, dateOnly: true)]),
                // Encrypted: a registry's fields carry birth dates, ID and tax numbers (review of #535); kept for staff and a
                // registrar's later needs (Crypt::decryptString), read by nothing yet.
                'whmcs_fields' => $fieldsOf !== [] ? Crypt::encryptString(json_encode($fieldsOf, JSON_UNESCAPED_UNICODE)) : null,
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
                // A module with no driver here, or none: the manual connection, never the TLD's registrar (reviews of #535: the
                // sync would find it «not ours» there and end it).
                'registrar_connection_id' => ($this->connections[$registrar] ?? $this->manual())->id,
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
                'notes' => WhmcsNoteImport::text($row['additionalnotes'] ?? null), // unescaped, as every note (#546)
                'meta' => $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => $this->date($row['created_at'] ?? null) ?? $this->date($row['registrationdate'] ?? null) ?? now()->toDateTimeString(),
                'updated_at' => $this->date($row['updated_at'] ?? null) ?? now()->toDateTimeString(),
            ];
            $this->counts['domains']++;
            $this->counts['handles'] += count($roleHandles);
            $this->counts['handles_kept'] += count($kept);
        }
        // One live row per name here (live_fqdn): of WHMCS's live rows of a name, the current one stays (active before
        // pending, grace, redemption, expired; then the later expiry, then the newer row); the others come cancelled, listed.
        $rank = [DomainStatus::Active->value => 0, DomainStatus::PendingTransfer->value => 1, DomainStatus::PendingRegister->value => 1,
            DomainStatus::Grace->value => 2, DomainStatus::Redemption->value => 3, DomainStatus::Expired->value => 4];
        foreach ($live as $fqdn => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            usort($indexes, fn (int $a, int $b) => [$rank[$rows[$a]['status']] ?? 9, -strtotime((string) ($rows[$a]['expires_at'] ?? '1970-01-01')), -$rows[$a]['id']]
                <=> [$rank[$rows[$b]['status']] ?? 9, -strtotime((string) ($rows[$b]['expires_at'] ?? '1970-01-01')), -$rows[$b]['id']]);
            foreach (array_slice($indexes, 1) as $index) {
                $this->notes[] = "Domain #{$rows[$index]['id']} {$fqdn}: also live as #{$rows[$indexes[0]]['id']} in WHMCS, imported cancelled: check them.";
                $rows[$index]['status'] = DomainStatus::Cancelled->value;
            }
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
        // Openprovider's handles only (the module's), for a domain at Openprovider in WHMCS (reviews of #535).
        foreach ($this->rows("SELECT dh.domain_id, dh.type, h.handle, h.data FROM wDomain_handle dh JOIN wHandles h ON h.id = dh.handle_id
            JOIN tbldomains d ON d.id = dh.domain_id WHERE LOWER(h.registrar) = 'openprovider' AND LOWER(TRIM(d.registrar)) = 'openprovider' ORDER BY dh.id") as $row) {
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
        // Its years as it says them: «1 Year/s», «1 Έτος/η», and the older «2 Χρόνια» (review of #535: 800 on myip).
        if (preg_match('/(\d+)\s*(?:Year|Έτ|Χρόν)/u', $description, $count)) {
            $years = max(1, min(10, (int) $count[1]));
        }
        $start = null;
        if (preg_match('#\((\d{1,4}[./-]\d{1,2}[./-]\d{1,4})\s*-\s*(\d{1,4}[./-]\d{1,2}[./-]\d{1,4})\)#', $description, $dates)) {
            $start = $this->parsed($dates[1]);
            $end = $this->parsed($dates[2]);
            // Its own dates win over any wording: WHMCS's end is the day before the next expiry.
            if ($start !== null && $end !== null && $end->greaterThan($start)) {
                $years = max(1, min(10, (int) round($start->diffInMonths($end->copy()->addDay()) / 12)));
            }
        }
        $start ??= ($due = $this->date($record['duedate'] ?? null, dateOnly: true)) !== null ? Carbon::parse($due) : null;
        if ($start === null) {
            return ['period_start' => null, 'period_end' => null, 'kind' => InvoiceItem::KIND_CHARGE];
        }

        return ['period_start' => $start->toDateString(), 'period_end' => $start->copy()->addYearsNoOverflow($years)->toDateString()];
    }

    /** A date in WHMCS's DateFormat, never an overflowing one (31/02). */
    private function parsed(string $value): ?Carbon
    {
        $parsed = \DateTime::createFromFormat('!' . $this->phpDateFormat(), $value);

        return $parsed !== false && $parsed->format($this->phpDateFormat()) === $value ? Carbon::instance($parsed) : null;
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

    /** @return array<int, array{expires: ?string, years: int, ended: bool, pending: bool}> */
    private function imported(): array
    {
        $ended = [DomainStatus::TransferredAway->value, DomainStatus::Cancelled->value, DomainStatus::Deleted->value];
        $pending = [DomainStatus::PendingRegister->value, DomainStatus::PendingTransfer->value];

        return $this->imported ??= DB::table('domains')->get(['id', 'expires_at', 'years', 'status'])
            ->mapWithKeys(fn ($domain) => [(int) $domain->id => ['expires' => $domain->expires_at !== null ? substr((string) $domain->expires_at, 0, 10) : null,
                'years' => max(1, (int) $domain->years), 'ended' => in_array($domain->status, $ended, true), 'pending' => in_array($domain->status, $pending, true)]])->all();
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
