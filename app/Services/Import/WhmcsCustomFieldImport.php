<?php

namespace App\Services\Import;

use App\Models\Service;
use App\Services\Tax\EuVat;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * WHMCS's custom fields (tblcustomfields / tblcustomfieldsvalues) into the fields MyCS has, never as custom fields of
 * its own (owner 2026-10-30). Fields are known by their name, as each company named them (sweep of both, 2026-10-30):
 *
 * - The account's (myip; nixpal has none): its fiscal details lived there, not in tblclients («ΑΦΜ / Vies Vat No»,
 *   «ΔΟΥ», «Δραστηριότητα επιχείρησης»: 651, 630, 599 filled) → its primary contact's tax_id, tax_office, activity (raw,
 *   as the plugin sent them: Ekdosi reads the number as it did); «Θα ήθελα να εκδοθεί τιμολόγιο» → document_type
 *   (ticked: invoice, unticked: receipt, never set: none chosen), as Ekdosi's MycsPayload reads it back; «Γκρινιάρης» →
 *   immediate_invoicing; «Σημειώσεις» → a note on the account for staff. A value the account already has stays.
 * - A service's: an IP («IP Address», «IP», «ipaddress», an addon's «Dedicated IP») → dedicated_ip when it has none;
 *   Centova's (username, port, servertype, sourcetype) → the properties its module reads; a licensing product's are its
 *   import's (left as they are); any other one filled → the service's notes, for staff. Never a password: listed only.
 */
final class WhmcsCustomFieldImport
{
    /** @var array<string, int> */
    private array $counts = ['accounts' => 0, 'notes' => 0, 'ips' => 0, 'centova' => 0, 'service_notes' => 0, 'skipped' => 0];

    /** @var list<string> */
    private array $notes = [];

    /**
     * The account's fields, by their whole name (folded) and type: never a prefix (ADV-544-3: a «VAT exempt» tickbox is
     * not the ΑΦΜ). One not here is listed (ADV-544-8).
     */
    private const CLIENT_FIELDS = [
        'ΑΦΜ / VIES VAT NO' => ['tax_id', 'text'], 'ΑΦΜ' => ['tax_id', 'text'], 'ΔΟΥ' => ['tax_office', 'text'],
        'ΔΡΑΣΤΗΡΙΟΤΗΤΑ ΕΠΙΧΕΙΡΗΣΗΣ' => ['activity', 'text'], 'ΘΑ ΗΘΕΛΑ ΝΑ ΕΚΔΟΘΕΙ ΤΙΜΟΛΟΓΙΟ' => ['document_type', 'tickbox'],
        'ΓΚΡΙΝΙΑΡΗΣ' => ['immediate_invoicing', 'tickbox'], 'ΣΗΜΕΙΩΣΕΙΣ' => ['note', 'textarea'],
    ];

    /** The columns' sizes: a longer value is listed, never cut or failing the import on MariaDB (ADV-544-1). */
    private const SIZES = ['tax_id' => 50, 'tax_office' => 255, 'activity' => 255];

    /** Ticked, as Ekdosi's WHMCS path read a tickbox (PendingWhmcsInvoice, ADV-544-2). */
    private const TICKED = ['on', '1', 'yes', 'true', 'ναι', 'checked'];

    /** A secret, whatever it is called (ADV-544-4): its name or its label. */
    private const SECRET = '/passw(or)?d|passphrase|(^|[\\s_.-])pass($|[\\s_.:-])|pwd|(^|[\\s_.-])pw($|[\\s_.:-])|secret|token|credential'
        . '|(api|access|private|secret|ssh)[\\s_.-]?key|auth[\\s_.-]?code|\\bepp\\b|κωδικ|συνθηματικ/iu';

    private const IP_FIELDS = ['IP', 'IP ADDRESS', 'IPADDRESS', 'IPADDR', 'DEDICATED IP'];

    private const CENTOVA_FIELDS = ['username' => 'Centova account', 'port' => 'Centova port', 'servertype' => 'Centova server type',
        'sourcetype' => 'Centova AutoDJ type'];

    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        $fields = $this->rows('SELECT id, type, relid, fieldname, fieldtype FROM tblcustomfields');
        if ($fields === null) {
            $this->notes[] = 'Custom fields: this WHMCS has no tblcustomfields: none imported.';

            return ['counts' => $this->counts, 'notes' => $this->notes];
        }
        $byId = [];
        foreach ($fields as $field) {
            $byId[(int) $field['id']] = $field;
        }
        $values = $this->rows('SELECT fieldid, relid, value FROM tblcustomfieldsvalues ORDER BY id') ?? [];
        $client = [];
        $service = [];
        foreach ($values as $row) {
            $field = $byId[(int) $row['fieldid']] ?? null;
            if ($field === null) {
                continue;
            }
            match (strtolower((string) $field['type'])) {
                'client' => $client[(int) $row['relid']][] = [$field, (string) ($row['value'] ?? '')],
                'product' => $service[(int) $row['relid']][] = [$field, (string) ($row['value'] ?? '')],
                'addon' => $service[-(int) $row['relid']][] = [$field, (string) ($row['value'] ?? '')], // an addon's: its service below
                default => null,
            };
        }
        $this->clients($client, $fields);
        $this->services($service);

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /**
     * @param  array<int, list<array{0: array<string, mixed>, 1: string}>>  $byClient
     * @param  list<array<string, mixed>>  $fields
     */
    private function clients(array $byClient, array $fields): void
    {
        $known = [];
        foreach ($fields as $field) {
            if (strtolower((string) $field['type']) !== 'client') {
                continue;
            }
            $target = self::clientTarget($field);
            if ($target === null) {
                $this->notes[] = "An account field «{$field['fieldname']}» is not one MyCS has: not imported.";
            } else {
                $known[$target] = true;
            }
        }
        $primary = DB::table('contacts')->whereNotNull('primary_of_client_id')->get(['id', 'primary_of_client_id', 'tax_id', 'vat_number', 'tax_office', 'activity', 'document_type'])
            ->keyBy('primary_of_client_id');
        foreach ($primary as $clientId => $contact) {
            $set = [];
            $flags = [];
            // The field there, the account's row missing: what Ekdosi read from WHMCS, unticked (a receipt; ADV-544-5).
            if (isset($known['document_type'])) {
                $set['document_type'] = 'receipt';
            }
            foreach ($byClient[$clientId] ?? [] as [$field, $value]) {
                $target = self::clientTarget($field);
                $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')); // WHMCS keeps them escaped (ADV-544-7)
                $ticked = in_array(mb_strtolower($value), self::TICKED, true);
                if ($target === 'document_type') {
                    $set['document_type'] = $ticked ? 'invoice' : 'receipt';
                } elseif ($target === 'immediate_invoicing') {
                    $flags['immediate_invoicing'] = $ticked;
                } elseif ($target === 'note' && $value !== '') {
                    DB::table('client_notes')->insert(['client_id' => $clientId, 'staff_id' => null, 'sticky' => false,
                        'body' => "From WHMCS («{$field['fieldname']}»):\n{$value}", 'created_at' => now(), 'updated_at' => now()]);
                    $this->counts['notes']++;
                } elseif ($target !== null && $target !== 'note' && $value !== '') {
                    if (mb_strlen($value) > self::SIZES[$target]) {
                        $this->skip("Customer #{$clientId}: its «{$field['fieldname']}» is longer than the " . self::SIZES[$target] . ' characters kept: not imported, set it');

                        continue;
                    }
                    $set[$target] = $value;
                    // A foreign EU VAT number (its own prefix, not EL: 16 on myip) is the VIES one too, which MyCS's
                    // VIES check and reverse charge read (code review 3 of #544); the ΑΦΜ stays as the plugin sent it.
                    // Only with the ΑΦΜ it comes with (R2: never beside another one tblclients had).
                    $vies = $target === 'tax_id' && blank($contact->tax_id) && ctype_alpha(substr($value, 0, 2)) ? EuVat::parse($value) : null;
                    if ($vies !== null && $vies->country !== 'GR' && mb_strlen($vies->full()) <= 50) {
                        $set['vat_number'] = $vies->full();
                    }
                }
            }
            // A value the account already has (tblclients' own) stays.
            $set = array_filter($set, fn ($value, string $column) => blank($contact->{$column}), ARRAY_FILTER_USE_BOTH);
            if ($set !== []) {
                DB::table('contacts')->where('id', $contact->id)->update($set + ['updated_at' => now()]);
            }
            if (($flags['immediate_invoicing'] ?? false) === true) {
                DB::table('clients')->where('id', $clientId)->update(['immediate_invoicing' => true]);
            }
            if (array_diff_key($set, ['document_type' => true]) !== [] || ($flags['immediate_invoicing'] ?? false)) {
                $this->counts['accounts']++; // its fiscal details, not only the default receipt
            }
        }
    }

    /** @param array<int, list<array{0: array<string, mixed>, 1: string}>> $byService a service id, or minus an addon's id */
    private function services(array $byService): void
    {
        $addons = array_column($this->rows('SELECT id, hostingid FROM tblhostingaddons') ?? [], 'hostingid', 'id');
        $modules = array_column($this->rows('SELECT id, servertype FROM tblproducts') ?? [], 'servertype', 'id');
        $services = Service::query()->get(['id', 'dedicated_ip', 'notes'])->keyBy('id');
        $packages = array_column($this->rows('SELECT id, packageid FROM tblhosting') ?? [], 'packageid', 'id');
        // A licensing product's fields are its import's (WhmcsLicensingImport::products), whatever the order (ADV-544-6).
        $licensing = array_flip(array_map('intval', array_merge(
            array_keys(array_filter($modules, fn ($module) => strtolower(trim((string) $module)) === 'licensing')),
            array_column($this->rows('SELECT DISTINCT h.packageid FROM tblhosting h JOIN mod_licensing l ON l.serviceid = h.id') ?? [], 'packageid'),
        )));
        $kept = DB::table('properties')->where('model_type', Service::class)->get(['model_id', 'key'])
            ->groupBy('model_id')->map(fn ($rows) => $rows->pluck('key')->flip()->all())->all();
        foreach ($byService as $key => $fields) {
            $id = $key < 0 ? (int) ($addons[-$key] ?? 0) : $key;
            $model = $services[$id] ?? null;
            if ($model === null) {
                continue; // a service not imported, an addon of none
            }
            $module = strtolower(trim((string) ($modules[$packages[$id] ?? 0] ?? '')));
            if ($key > 0 && isset($licensing[(int) ($packages[$id] ?? 0)])) {
                continue;
            }
            $lines = [];
            foreach ($fields as [$field, $value]) {
                $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $raw = (string) $field['fieldname'];
                $name = trim(explode('|', $raw)[0]);
                $label = trim(explode('|', $raw)[1] ?? $name);
                if ($value === '' || isset($kept[$id][$raw])) {
                    continue; // nothing, or a licensing product's own field (its import's)
                }
                if (preg_match(self::SECRET, $name . ' ' . $label) === 1) {
                    $this->skip("Service #{$id}: «{$label}» is a password: not imported");

                    continue;
                }
                if (in_array(mb_strtoupper($name), self::IP_FIELDS, true)) {
                    if (blank($model->dedicated_ip) && filter_var($value, FILTER_VALIDATE_IP) !== false) {
                        $model->dedicated_ip = $value;
                        DB::table('services')->where('id', $id)->update(['dedicated_ip' => $value]);
                        $this->counts['ips']++;
                    } elseif ($value !== (string) $model->dedicated_ip) {
                        $lines[] = "{$label}: {$value}"; // another address, or not one: for staff
                    }

                    continue;
                }
                if ($module === 'centovacast' && isset(self::CENTOVA_FIELDS[strtolower($name)])) {
                    DB::table('properties')->updateOrInsert(['model_type' => Service::class, 'model_id' => $id, 'key' => 'centova_' . strtolower($name)],
                        ['name' => self::CENTOVA_FIELDS[strtolower($name)], 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
                    $this->counts['centova']++;

                    continue;
                }
                if ($module === 'centovacast' && strtolower($name) === 'transferlimit') {
                    continue; // the product's limit, set on its plan
                }
                $lines[] = "{$label}: {$value}";
            }
            if ($lines !== []) {
                $notes = trim(trim((string) $model->notes) . "\n\nFrom WHMCS's custom fields:\n" . implode("\n", $lines));
                DB::table('services')->where('id', $id)->update(['notes' => $notes]);
                $this->counts['service_notes']++;
            }
        }
    }

    /** @param array<string, mixed> $field */
    private static function clientTarget(array $field): ?string
    {
        $folded = preg_replace('/\s+/u', ' ', strtr(mb_strtoupper(trim((string) $field['fieldname'])), ['Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι',
            'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω', 'Ϊ' => 'Ι', 'Ϋ' => 'Υ'])) ?? '';
        [$target, $type] = self::CLIENT_FIELDS[$folded] ?? [null, null];

        return $target !== null && strtolower((string) ($field['fieldtype'] ?? $type)) === $type ? $target : null;
    }

    /** @return list<array<string, mixed>>|null its rows, or null on a WHMCS without the table */
    private function rows(string $sql): ?array
    {
        try {
            return $this->whmcs->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (preg_match('/doesn\'t exist|no such table|42S02/i', $e->getMessage() . ' ' . $e->getCode()) === 1) {
                return null;
            }
            throw $e;
        }
    }

    private function skip(string $why): void
    {
        $this->counts['skipped']++;
        $this->notes[] = $why . '.';
    }
}
