<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * WHMCS's notes, staff's own (owner 2026-10-30: «we need them, whatever they say»), the ones no other step brings
 * (sweep of both, 2026-10-30; a service's, an order's, a domain's and a ticket's come with them):
 *
 * - `tblnotes` → the account's notes (client_notes), by the admin who wrote them (staff keep WHMCS's admin ids), sticky,
 *   their dates (nixpal 2, myip 13).
 * - `tblclients.notes` → a note on the account (nixpal 3, myip 127).
 * - `tblinvoices.notes` → a note on the invoice's account, naming it: MyCS's invoices have no notes field (nixpal 66,
 *   myip 27).
 * - `tblhostingaddons.notes` → its service's notes, naming the addon (myip 4).
 *
 * Whole, never cut nor filtered; WHMCS's HTML escaping undone (`&#039;`, `<br />`). Rows are written directly (an
 * import: no audit of each).
 */
final class WhmcsNoteImport
{
    /** @var array<string, int> */
    private array $counts = ['notes' => 0, 'account_notes' => 0, 'invoice_notes' => 0, 'addon_notes' => 0, 'skipped' => 0];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        $clients = DB::table('clients')->pluck('created_at', 'id')->all();
        $staff = DB::table('staff')->pluck('id')->flip()->all();
        $rows = [];

        foreach ($this->rows('SELECT * FROM tblnotes ORDER BY id') ?? [] as $row) {
            $client = (int) ($row['userid'] ?? 0);
            $body = self::text($row['note'] ?? null);
            if ($body === null) {
                continue;
            }
            if (!array_key_exists($client, $clients)) {
                $this->skip("A WHMCS note (#{$row['id']}) of customer #{$client}, not here");

                continue;
            }
            $admin = (int) ($row['adminid'] ?? 0);
            if ($admin > 0 && !isset($staff[$admin])) {
                $body = "(WHMCS admin #{$admin})\n{$body}"; // its author, though not here (code review 5 of #546)
            }
            $rows[] = ['client_id' => $client, 'staff_id' => isset($staff[$admin]) ? $admin : null, 'sticky' => (bool) ($row['sticky'] ?? false),
                'body' => $body, 'created_at' => self::date($row['created'] ?? null) ?? now(), 'updated_at' => self::date($row['modified'] ?? null) ?? now()];
            $this->counts['notes']++;
        }

        foreach ($this->rows('SELECT id, notes FROM tblclients') ?? [] as $row) {
            $body = self::text($row['notes'] ?? null);
            if ($body !== null && array_key_exists((int) $row['id'], $clients)) {
                $rows[] = ['client_id' => (int) $row['id'], 'staff_id' => null, 'sticky' => false, 'body' => "From WHMCS (the account's notes):\n{$body}",
                    'created_at' => $clients[(int) $row['id']] ?? now(), 'updated_at' => now()];
                $this->counts['account_notes']++;
            }
        }

        foreach ($this->rows('SELECT id, userid, invoicenum, date, notes FROM tblinvoices') ?? [] as $row) {
            $body = self::text($row['notes'] ?? null);
            if ($body === null) {
                continue;
            }
            $client = (int) ($row['userid'] ?? 0);
            if (!array_key_exists($client, $clients)) {
                $this->skip("The notes of WHMCS invoice #{$row['id']}: its customer #{$client} is not here");

                continue;
            }
            $number = trim((string) ($row['invoicenum'] ?? '')) !== '' ? $row['invoicenum'] : $row['id'];
            $rows[] = ['client_id' => $client, 'staff_id' => null, 'sticky' => false, 'body' => "From WHMCS (invoice #{$number}'s notes):\n{$body}",
                'created_at' => self::date($row['date'] ?? null) ?? now(), 'updated_at' => now()];
            $this->counts['invoice_notes']++;
        }

        foreach (array_chunk($rows, 100) as $chunk) { // under MariaDB's packet with notes near 64 KB (code review 4)
            DB::table('client_notes')->insert($chunk);
        }

        $this->addons($clients);

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /**
     * An addon's notes: its service's, naming it (addons are its options here); a service WHMCS deleted: its account's
     * (code review 1 of #546: every note).
     *
     * @param  array<int, mixed>  $clients
     */
    private function addons(array $clients): void
    {
        $services = DB::table('services')->pluck('notes', 'id')->all();
        foreach ($this->rows('SELECT * FROM tblhostingaddons ORDER BY id') ?? [] as $row) {
            $body = self::text($row['notes'] ?? null);
            if ($body === null) {
                continue;
            }
            $service = (int) ($row['hostingid'] ?? 0);
            $name = trim((string) ($row['name'] ?? '')) !== '' ? $row['name'] : "#{$row['id']}";
            if (!array_key_exists($service, $services)) {
                $client = (int) ($row['userid'] ?? 0);
                if (!array_key_exists($client, $clients)) {
                    $this->skip("The notes of WHMCS addon #{$row['id']}: neither its service #{$service} nor its customer #{$client} is here");

                    continue;
                }
                DB::table('client_notes')->insert(['client_id' => $client, 'staff_id' => null, 'sticky' => false,
                    'body' => "From WHMCS (addon «{$name}» of service #{$service}, which WHMCS deleted):\n{$body}", 'created_at' => now(), 'updated_at' => now()]);
                $this->counts['addon_notes']++;

                continue;
            }
            $services[$service] = trim(trim((string) $services[$service]) . "\n\nAddon «{$name}» (WHMCS notes):\n{$body}");
            DB::table('services')->where('id', $service)->update(['notes' => $services[$service]]);
            $this->counts['addon_notes']++;
        }
    }

    /**
     * The text whole, WHMCS's HTML escaping undone (it stores every note escaped: a typed «&amp;» is «&amp;amp;»);
     * empty: none. Every note step's (services, orders, domains: code review 2 of #546).
     */
    public static function text(mixed $value): ?string
    {
        $text = trim(html_entity_decode(preg_replace('#<br\s*/?>#i', "\n", (string) $value) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $text;
    }

    private static function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, '0000') ? null : $value;
    }

    /** @return list<array<string, mixed>>|null its rows, or null on a WHMCS without the table or column */
    private function rows(string $sql): ?array
    {
        try {
            return $this->whmcs->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (preg_match('/doesn\'t exist|no such table|no such column|Unknown column|42S02|42S22/i', $e->getMessage() . ' ' . $e->getCode()) === 1) {
                $this->notes[] = 'Notes: «' . strtok($sql, "\n") . '» could not be read on this WHMCS (no such table or column): none from it.';

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
