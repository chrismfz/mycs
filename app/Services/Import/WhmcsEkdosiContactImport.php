<?php

namespace App\Services\Import;

use App\Services\Tax\EuVat;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * E4 of the Ekdosi bridge (docs/ekdosi-bridge.md «Importer»): who each service's and domain's fiscal document is issued
 * to, from Ekdosi's WHMCS plugin, so that MyCS's feed sends the same recipient to Ekdosi's «Εισερχόμενα» after the
 * cut-over.
 *
 * - `mod_ekdosi_contacts` → the account's contacts (company, ΑΦΜ, VIES number, ΔΟΥ, activity, address), only those
 *   with an assignment (owner 2026-10-30: the others stay in WHMCS's archive).
 * - `mod_ekdosi_routing` → `services.contact_id` (hosting) / `domains.contact_id` (domain), ended items too.
 * - `is_receipt` is per assignment there, per contact here (`document_type`): all its assignments receipts → receipt,
 *   else invoice; both kinds on one contact are listed, never chosen silently (none on the copies).
 * - An assignment of an item now another customer's (moved in WHMCS), of an item or contact not here: skipped and
 *   listed (owner 2026-10-30); the document goes to the item's account until staff set a contact.
 * - A WHMCS without the plugin (nixpal): nothing, and it says so. Rows are written directly (an import: no VIES check
 *   after the commit, no audit of each).
 */
final class WhmcsEkdosiContactImport
{
    /** @var array<string, int> */
    private array $counts = ['contacts' => 0, 'services' => 0, 'domains' => 0, 'skipped' => 0];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        $contacts = $this->rows('SELECT * FROM mod_ekdosi_contacts ORDER BY id');
        $routing = $this->rows('SELECT * FROM mod_ekdosi_routing ORDER BY id');
        if ($contacts === null || $routing === null) {
            $this->notes[] = 'Ekdosi contacts: this WHMCS has no Ekdosi plugin tables (mod_ekdosi_contacts, mod_ekdosi_routing): none imported.';

            return ['counts' => $this->counts, 'notes' => $this->notes];
        }
        $byContact = [];
        foreach ($routing as $row) {
            $byContact[(int) $row['contactid']][] = $row;
        }
        $clients = DB::table('clients')->pluck('id')->flip()->all();
        $owners = [
            'hosting' => DB::table('services')->pluck('client_id', 'id')->all(),
            'domain' => DB::table('domains')->pluck('client_id', 'id')->all(),
        ];
        foreach ($contacts as $contact) {
            $id = (int) $contact['id'];
            $client = (int) $contact['userid'];
            $assigned = $byContact[$id] ?? [];
            if ($assigned === []) {
                continue; // never assigned: left in WHMCS's archive
            }
            if (!isset($clients[$client])) {
                $this->skip(count($assigned), "Ekdosi contact #{$id}: its customer #{$client} is not here");

                continue;
            }
            $receipts = count(array_filter($assigned, fn (array $row) => (bool) $row['is_receipt']));
            if ($receipts > 0 && $receipts < count($assigned)) {
                $this->notes[] = "Ekdosi contact #{$id} (customer #{$client}): {$receipts} of its " . count($assigned)
                    . ' assignments are receipts, the rest invoices: imported as «invoice», check it.';
            }
            $contactId = $this->insert($contact, $receipts === count($assigned) ? 'receipt' : 'invoice');
            $this->counts['contacts']++;
            foreach ($assigned as $row) {
                $this->assign($row, $contactId, $client, $owners);
            }
        }
        $known = array_flip(array_map('intval', array_column($contacts, 'id')));
        foreach ($routing as $row) {
            if (!isset($known[(int) $row['contactid']])) {
                $this->skip(1, "Ekdosi assignment #{$row['id']}: its contact #{$row['contactid']} is not in mod_ekdosi_contacts");
            }
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /** @param array<string, array<int, int>> $owners */
    private function assign(array $row, int $contactId, int $client, array $owners): void
    {
        $type = strtolower(trim((string) $row['service_type']));
        $item = (int) $row['serviceid'];
        if (!isset($owners[$type])) {
            $this->skip(1, "Ekdosi assignment #{$row['id']}: a «{$type}» is neither a service nor a domain");

            return;
        }
        if (!isset($owners[$type][$item])) {
            $this->skip(1, "Ekdosi assignment #{$row['id']}: {$type} #{$item} is not here");

            return;
        }
        if ((int) $owners[$type][$item] !== $client) {
            $this->skip(1, "Ekdosi assignment #{$row['id']}: {$type} #{$item} is customer #{$owners[$type][$item]}'s now, its contact customer #{$client}'s: "
                . 'not assigned, its documents go to its account until staff set a contact');

            return;
        }
        DB::table($type === 'hosting' ? 'services' : 'domains')->where('id', $item)->update(['contact_id' => $contactId]);
        $this->counts[$type === 'hosting' ? 'services' : 'domains']++;
    }

    private function insert(array $row, string $documentType): int
    {
        $value = fn (string $key) => isset($row[$key]) && trim((string) $row[$key]) !== '' ? trim((string) $row[$key]) : null;
        $country = self::country($value('country'));
        if ($country === null && $value('country') === null) {
            // None given (45 on myip's copy): its account's, as the plugin issued it to that account's country.
            $country = DB::table('contacts')->where('primary_of_client_id', (int) $row['userid'])->value('country');
        }
        if ($country === null) {
            $this->notes[] = "Ekdosi contact #{$row['id']} (customer #{$row['userid']}): its country «" . ($value('country') ?? '')
                . '» is not one the app knows: imported without one, set it.';
        }

        return (int) DB::table('contacts')->insertGetId([
            'client_id' => (int) $row['userid'],
            'type' => $value('company_name') !== null ? 'business' : 'individual',
            'company_name' => $value('company_name'),
            'tax_id' => $value('gr_vatno'),
            'vat_number' => $value('vies_vatno'),
            'tax_office' => $value('tax_office'),
            'activity' => $value('description'),
            'address1' => $value('address1'),
            'address2' => $value('address2'),
            'city' => $value('city'),
            'postcode' => $value('postal_code'),
            'country' => $country,
            'email' => $value('email'),
            'phone' => $value('telephone'),
            'notes' => $value('comments'),
            'document_type' => $documentType,
            'created_at' => ($created = $value('created_at')) !== null && !str_starts_with($created, '0000') ? $created : now(),
            'updated_at' => now(),
        ]);
    }

    /** Greek names the plugin's contacts have («ΕΛΛΑΔΑ», «Κύπρος»: 126 on myip's copy), beside what EuVat knows. */
    private const GREEK_NAMES = ['ΕΛΛΑΔΑ' => 'GR', 'ΕΛΛΑΣ' => 'GR', 'ΚΥΠΡΟΣ' => 'CY'];

    private static function country(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $upper = strtr(mb_strtoupper($name), ['Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω']);

        return self::GREEK_NAMES[$upper] ?? EuVat::iso($name);
    }

    /** @return list<array<string, mixed>>|null its rows, or null on a WHMCS without the table */
    private function rows(string $sql): ?array
    {
        try {
            return $this->whmcs->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return null;
        }
    }

    private function skip(int $count, string $why): void
    {
        $this->counts['skipped'] += $count;
        $this->notes[] = $why . '.';
    }
}
