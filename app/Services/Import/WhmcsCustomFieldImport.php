<?php

namespace App\Services\Import;

use App\Models\Service;
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

    /** The account's fields, by what their name starts with (folded). */
    private const CLIENT_FIELDS = [
        'ΑΦΜ' => 'tax_id', 'VAT' => 'tax_id', 'ΔΟΥ' => 'tax_office', 'ΔΡΑΣΤΗΡΙΟΤΗΤΑ' => 'activity',
        'ΘΑ ΗΘΕΛΑ ΝΑ ΕΚΔΟΘΕΙ ΤΙΜΟΛΟΓΙΟ' => 'document_type', 'ΓΚΡΙΝΙΑΡΗΣ' => 'immediate_invoicing', 'ΣΗΜΕΙΩΣΕΙΣ' => 'note',
    ];

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
        $this->clients($client);
        $this->services($service);

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /** @param array<int, list<array{0: array<string, mixed>, 1: string}>> $byClient */
    private function clients(array $byClient): void
    {
        $primary = DB::table('contacts')->whereNotNull('primary_of_client_id')->get(['id', 'primary_of_client_id', 'tax_id', 'tax_office', 'activity', 'document_type'])
            ->keyBy('primary_of_client_id');
        foreach ($byClient as $clientId => $fields) {
            $contact = $primary[$clientId] ?? null;
            if ($contact === null) {
                continue; // a customer not imported
            }
            $set = [];
            $flags = [];
            foreach ($fields as [$field, $value]) {
                $target = self::clientTarget((string) $field['fieldname']);
                $value = trim($value);
                if ($target === 'document_type') {
                    $set['document_type'] = $value === 'on' ? 'invoice' : 'receipt'; // a row: chosen once, ticked or not
                } elseif ($target === 'immediate_invoicing') {
                    $flags['immediate_invoicing'] = $value === 'on';
                } elseif ($target === 'note' && $value !== '') {
                    DB::table('client_notes')->insert(['client_id' => $clientId, 'staff_id' => null, 'sticky' => false,
                        'body' => "From WHMCS («{$field['fieldname']}»):\n{$value}", 'created_at' => now(), 'updated_at' => now()]);
                    $this->counts['notes']++;
                } elseif ($target !== null && $target !== 'note' && $value !== '') {
                    $set[$target] = mb_substr($value, 0, 255);
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
            if ($set !== [] || $flags !== []) {
                $this->counts['accounts']++;
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
        $kept = DB::table('properties')->where('model_type', Service::class)->get(['model_id', 'key'])
            ->groupBy('model_id')->map(fn ($rows) => $rows->pluck('key')->flip()->all())->all();
        foreach ($byService as $key => $fields) {
            $id = $key < 0 ? (int) ($addons[-$key] ?? 0) : $key;
            $model = $services[$id] ?? null;
            if ($model === null) {
                continue; // a service not imported, an addon of none
            }
            $module = strtolower(trim((string) ($modules[$packages[$id] ?? 0] ?? '')));
            $lines = [];
            foreach ($fields as [$field, $value]) {
                $value = trim($value);
                $raw = (string) $field['fieldname'];
                $name = trim(explode('|', $raw)[0]);
                $label = trim(explode('|', $raw)[1] ?? $name);
                if ($value === '' || isset($kept[$id][$raw])) {
                    continue; // nothing, or a licensing product's own field (its import's)
                }
                if (str_contains(strtolower($name), 'password')) {
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

    private static function clientTarget(string $name): ?string
    {
        $folded = strtr(mb_strtoupper(trim($name)), ['Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω', 'Ϊ' => 'Ι', 'Ϋ' => 'Υ']);
        foreach (self::CLIENT_FIELDS as $start => $target) {
            if (str_starts_with($folded, $start)) {
                return $target;
            }
        }

        return null;
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
