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
        // customer|type|item => its first assignment's id, as the plugin's resolveInvoice looks a route up (the invoice's
        // customer, the item): a second one is listed, never applied (ADV-542-3); a moved item's new owner's is its own (R2-542-1).
        $first = [];
        foreach ($routing as $row) {
            $byContact[(int) ($row['contactid'] ?? 0)][] = $row;
            $first[(int) ($row['userid'] ?? 0) . '|' . strtolower(trim((string) ($row['service_type'] ?? ''))) . '|' . (int) ($row['serviceid'] ?? 0)] ??= (int) $row['id'];
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
            // The assignments that apply, first: a skipped one decides nothing (ADV-542-1), and a contact with none is
            // not imported (ADV-542-4: as one never assigned).
            $applied = array_values(array_filter($assigned, fn (array $row) => $this->applies($row, $client, $owners, $first)));
            if ($applied === []) {
                continue;
            }
            // A country written that the app cannot read is never sent as none, which Ekdosi reads as Greece (R3-542-1):
            // not assigned, its documents go to its account until staff set it.
            $written = trim((string) ($contact['country'] ?? ''));
            if ($written !== '' && self::country($written) === null) {
                $this->skip(count($applied), "Ekdosi contact #{$id} (customer #{$client}): its country «{$written}» is not one the app knows: "
                    . 'not imported nor assigned, its documents go to its account until staff add it');

                continue;
            }
            $receipts = count(array_filter($applied, fn (array $row) => (bool) ($row['is_receipt'] ?? false)));
            if ($receipts > 0 && $receipts < count($applied)) {
                $this->notes[] = "Ekdosi contact #{$id} (customer #{$client}): {$receipts} of its " . count($applied)
                    . ' assignments are receipts, the rest invoices: imported as «invoice», check it.';
            }
            $contactId = $this->insert($contact, $receipts === count($applied) ? 'receipt' : 'invoice');
            $this->counts['contacts']++;
            foreach ($applied as $row) {
                $type = strtolower(trim((string) ($row['service_type'] ?? '')));
                DB::table($type === 'hosting' ? 'services' : 'domains')->where('id', (int) ($row['serviceid'] ?? 0))->update(['contact_id' => $contactId]);
                $this->counts[$type === 'hosting' ? 'services' : 'domains']++;
            }
        }
        $known = array_flip(array_map('intval', array_column($contacts, 'id')));
        foreach ($routing as $row) {
            if (!isset($known[(int) ($row['contactid'] ?? 0)])) {
                $this->skip(1, "Ekdosi assignment #{$row['id']}: its contact #{$row['contactid']} is not in mod_ekdosi_contacts");
            }
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /**
     * Whether the assignment is applied; else skipped and listed.
     *
     * @param  array<string, array<int, int>>  $owners
     * @param  array<string, int>  $first
     */
    private function applies(array $row, int $client, array $owners, array $first): bool
    {
        // The plugin finds a route by its own customer (routing.userid = the invoice's), never the contact's: two that
        // differ are their own case (code review 4 of #542; none on the copies).
        if ((int) ($row['userid'] ?? 0) !== $client) {
            $this->skip(1, "Ekdosi assignment #{$row['id']}: its customer #" . (int) ($row['userid'] ?? 0) . " is not its contact's (#{$client}): not assigned");

            return false;
        }
        $type = strtolower(trim((string) ($row['service_type'] ?? '')));
        $item = (int) ($row['serviceid'] ?? 0);
        if (!isset($owners[$type])) {
            $this->skip(1, "Ekdosi assignment #{$row['id']}: a «{$type}» is neither a service nor a domain");

            return false;
        }
        if (!isset($owners[$type][$item])) {
            $this->skip(1, "Ekdosi assignment #{$row['id']}: {$type} #{$item} is not here");

            return false;
        }
        if ((int) $owners[$type][$item] !== $client) {
            $this->skip(1, "Ekdosi assignment #{$row['id']}: {$type} #{$item} is customer #{$owners[$type][$item]}'s now, its contact customer #{$client}'s: "
                . 'not assigned, its documents go to its account until staff set a contact');

            return false;
        }
        if (($earlier = $first["{$client}|{$type}|{$item}"]) !== (int) $row['id']) {
            $this->skip(1, "Ekdosi assignment #{$row['id']}: {$type} #{$item} has an earlier one (#{$earlier}), which the plugin used: "
                . 'not applied (the earlier one is, unless it is listed too): check which is right');

            return false;
        }

        return true;
    }

    private function insert(array $row, string $documentType): int
    {
        $value = fn (string $key) => isset($row[$key]) && trim((string) $row[$key]) !== '' ? trim((string) $row[$key]) : null;
        $country = self::country($value('country'));
        if ($country === null && $value('country') === null && $value('vies_vatno') !== null) {
            // None given: its VIES number's (EL: GR), never its account's (ADV-542-2: a Dutch company is not a Greek one).
            $country = EuVat::iso(mb_substr(preg_replace('/\s+/', '', $value('vies_vatno')) ?? '', 0, 2));
        }
        $vies = $value('vies_vatno');
        if ($vies !== null && mb_strlen($vies) > 50) {
            $this->notes[] = "Ekdosi contact #{$row['id']} (customer #{$row['userid']}): its VIES number is longer than the 50 characters kept: imported without it, set it.";
            $vies = null;
        }
        $notes = $value('comments');
        if ($country === null) {
            $this->notes[] = "Ekdosi contact #{$row['id']} (customer #{$row['userid']}): its country «" . ($value('country') ?? '')
                . '» is not one the app knows: imported without one, set it.';
            // What WHMCS had, for staff setting it (code review 7 of #542).
            $notes = $value('country') === null ? $notes : trim(($notes ?? '') . "\nCountry in WHMCS: " . $value('country'));
        }
        if ($documentType === 'invoice' && ($value('gr_vatno') === null || $value('tax_office') === null || $value('address1') === null)) {
            $this->notes[] = "Ekdosi contact #{$row['id']} (customer #{$row['userid']}): an invoice recipient without ΑΦΜ, ΔΟΥ or address: complete it.";
        }

        return (int) DB::table('contacts')->insertGetId([
            'client_id' => (int) $row['userid'],
            'type' => $value('company_name') !== null ? 'business' : 'individual',
            'company_name' => $value('company_name'),
            'tax_id' => $value('gr_vatno'),
            'vat_number' => $vies,
            'tax_office' => $value('tax_office'),
            'activity' => $value('description'),
            'address1' => $value('address1'),
            'address2' => $value('address2'),
            'city' => $value('city'),
            'postcode' => $value('postal_code'),
            'country' => $country,
            'email' => $value('email'),
            'phone' => $value('telephone'),
            'notes' => $notes,
            'document_type' => $documentType,
            'created_at' => ($created = $value('created_at')) !== null && !str_starts_with($created, '0000') ? $created : now(),
            'updated_at' => now(),
        ]);
    }

    /** Greek names the plugin's contacts have («ΕΛΛΑΔΑ», «Κύπρος»: 126 on myip's copy), beside what EuVat knows. */
    private const GREEK_NAMES = [
        'ΕΛΛΑΔΑ' => 'GR', 'ΕΛΛΑΣ' => 'GR', 'ΕΛΛΗΝΙΚΗ ΔΗΜΟΚΡΑΤΙΑ' => 'GR', 'ΚΥΠΡΟΣ' => 'CY',
        // Ekdosi's IsoCountry resolves these too (R3-542-1): an EU or nearby country written in Greek.
        'ΙΤΑΛΙΑ' => 'IT', 'ΓΕΡΜΑΝΙΑ' => 'DE', 'ΓΑΛΛΙΑ' => 'FR', 'ΙΣΠΑΝΙΑ' => 'ES', 'ΒΟΥΛΓΑΡΙΑ' => 'BG', 'ΡΟΥΜΑΝΙΑ' => 'RO',
        'ΟΛΛΑΝΔΙΑ' => 'NL', 'ΚΑΤΩ ΧΩΡΕΣ' => 'NL', 'ΒΕΛΓΙΟ' => 'BE', 'ΑΥΣΤΡΙΑ' => 'AT', 'ΠΟΛΩΝΙΑ' => 'PL', 'ΠΟΡΤΟΓΑΛΙΑ' => 'PT',
        'ΣΟΥΗΔΙΑ' => 'SE', 'ΔΑΝΙΑ' => 'DK', 'ΙΡΛΑΝΔΙΑ' => 'IE', 'ΦΙΝΛΑΝΔΙΑ' => 'FI', 'ΛΟΥΞΕΜΒΟΥΡΓΟ' => 'LU', 'ΜΑΛΤΑ' => 'MT',
        'ΣΛΟΒΕΝΙΑ' => 'SI', 'ΣΛΟΒΑΚΙΑ' => 'SK', 'ΚΡΟΑΤΙΑ' => 'HR', 'ΕΣΘΟΝΙΑ' => 'EE', 'ΛΕΤΟΝΙΑ' => 'LV', 'ΛΙΘΟΥΑΝΙΑ' => 'LT',
        'ΤΣΕΧΙΑ' => 'CZ', 'ΤΣΕΧΙΚΗ ΔΗΜΟΚΡΑΤΙΑ' => 'CZ', 'ΟΥΓΓΑΡΙΑ' => 'HU', 'ΕΛΒΕΤΙΑ' => 'CH', 'ΝΟΡΒΗΓΙΑ' => 'NO',
        'ΑΓΓΛΙΑ' => 'GB', 'ΒΡΕΤΑΝΙΑ' => 'GB', 'ΜΕΓΑΛΗ ΒΡΕΤΑΝΙΑ' => 'GB', 'ΗΝΩΜΕΝΟ ΒΑΣΙΛΕΙΟ' => 'GB', 'ΗΠΑ' => 'US',
        'ΗΝΩΜΕΝΕΣ ΠΟΛΙΤΕΙΕΣ' => 'US', 'ΑΜΕΡΙΚΗ' => 'US', 'ΑΛΒΑΝΙΑ' => 'AL', 'ΣΕΡΒΙΑ' => 'RS', 'ΤΟΥΡΚΙΑ' => 'TR',
        'ΒΟΡΕΙΑ ΜΑΚΕΔΟΝΙΑ' => 'MK', 'ΣΚΟΠΙΑ' => 'MK', 'ΠΓΔΜ' => 'MK', 'ΡΩΣΙΑ' => 'RU', 'ΟΥΚΡΑΝΙΑ' => 'UA', 'ΙΣΡΑΗΛ' => 'IL',
        // Codes and names EuVat does not read as these (Ekdosi's aliases).
        'UK' => 'GB', 'EL' => 'GR', 'HELLAS' => 'GR', 'GRC' => 'GR', 'CYP' => 'CY', 'GBR' => 'GB', 'ENGLAND' => 'GB',
        'GREAT BRITAIN' => 'GB', 'USA' => 'US', 'HOLLAND' => 'NL', 'DEUTSCHLAND' => 'DE', 'ITALIA' => 'IT', 'ESPANA' => 'ES',
    ];

    private static function country(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $upper = preg_replace('/\s+/u', ' ', trim(strtr(mb_strtoupper($name), ['Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο',
            'Ύ' => 'Υ', 'Ώ' => 'Ω', 'Ϊ' => 'Ι', 'Ϋ' => 'Υ', '.' => '']))) ?? '';

        return self::GREEK_NAMES[$upper] ?? EuVat::iso($name);
    }

    /** @return list<array<string, mixed>>|null its rows, or null on a WHMCS without the table */
    private function rows(string $sql): ?array
    {
        try {
            return $this->whmcs->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // A WHMCS without the plugin (its table missing); any other failure (a lost connection) is one, loudly.
            if (preg_match('/doesn\'t exist|no such table|42S02/i', $e->getMessage() . ' ' . $e->getCode()) === 1) {
                return null;
            }
            throw $e;
        }
    }

    private function skip(int $count, string $why): void
    {
        $this->counts['skipped'] += $count;
        $this->notes[] = $why . '.';
    }
}
