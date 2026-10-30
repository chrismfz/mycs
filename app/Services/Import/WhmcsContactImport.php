<?php

namespace App\Services\Import;

use App\Models\Contact;
use App\Services\Tax\EuVat;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * WHMCS's contacts (tblcontacts: nixpal 17, myip 435; none a login, those come with tblusers) as the account's contacts,
 * in MyCS's own fields (owner 2026-10-30): names, company, address, email, phone, tax id, and the mails each takes
 * (its generalemails, invoiceemails… flags as email_preferences: none ticked is none).
 *
 * One that is an Ekdosi contact imported already (WhmcsEkdosiContactImport) is not made twice: the one linked to it by
 * mod_ekdosi_contacts.legacy_contact_id, or the account's one with its email and company, gets only what it lacks. Any
 * other stays its own contact, as in WHMCS (82 on myip share their account's email: a company, another role).
 */
final class WhmcsContactImport
{
    /** WHMCS's per-contact mail flags → Contact::EMAIL_PREFERENCES. */
    private const FLAGS = ['generalemails' => 'general', 'invoiceemails' => 'invoice', 'supportemails' => 'support',
        'productemails' => 'product', 'domainemails' => 'domain', 'affiliateemails' => 'affiliate'];

    /** The fields filled on an Ekdosi contact it is, when it has none. */
    private const FILL = ['first_name', 'last_name', 'phone', 'address1', 'address2', 'city', 'state', 'postcode', 'country'];

    /** @var array<string, int> */
    private array $counts = ['contacts' => 0, 'merged' => 0, 'skipped' => 0];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        try {
            $rows = $this->whmcs->query('SELECT * FROM tblcontacts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (preg_match('/doesn\'t exist|no such table|42S02/i', $e->getMessage() . ' ' . $e->getCode()) === 1) {
                return ['counts' => $this->counts, 'notes' => $this->notes];
            }
            throw $e;
        }
        $clients = DB::table('clients')->pluck('id')->flip()->all();
        $legacy = $this->legacyLinks();
        $insert = [];
        foreach ($rows as $row) {
            $client = (int) ($row['userid'] ?? 0);
            if (!isset($clients[$client])) {
                $this->counts['skipped']++;
                $this->notes[] = "WHMCS contact #{$row['id']}: its customer #{$client} is not here.";

                continue;
            }
            $fields = $this->fields($row);
            $ekdosi = $this->ekdosiContact($client, (int) $row['id'], $fields, $legacy);
            if ($ekdosi !== null) {
                $fill = array_filter(array_intersect_key($fields, array_flip(self::FILL)), fn ($value, string $key) => $value !== null && blank($ekdosi->{$key}),
                    ARRAY_FILTER_USE_BOTH);
                if ($ekdosi->email_preferences === null) {
                    $fill['email_preferences'] = $fields['email_preferences'];
                }
                if ($fill !== []) {
                    DB::table('contacts')->where('id', $ekdosi->id)->update($fill + ['updated_at' => now()]);
                }
                $this->counts['merged']++;

                continue;
            }
            $insert[] = $fields + ['client_id' => $client, 'created_at' => self::date($row['created_at'] ?? null) ?? now(), 'updated_at' => now()];
            $this->counts['contacts']++;
        }
        foreach (array_chunk($insert, 200) as $chunk) {
            DB::table('contacts')->insert($chunk);
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /** @return array<string, mixed> its MyCS fields */
    private function fields(array $row): array
    {
        $value = function (string $key, int $max = 255) use ($row) {
            $text = trim(html_entity_decode((string) ($row[$key] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')); // WHMCS keeps them escaped

            return $text === '' ? null : mb_substr($text, 0, $max);
        };
        $kinds = [];
        foreach (self::FLAGS as $flag => $kind) {
            if ((int) ($row[$flag] ?? 0) === 1) {
                $kinds[] = $kind;
            }
        }
        $country = $value('country');

        return [
            'type' => $value('companyname') !== null ? 'business' : 'individual',
            'first_name' => $value('firstname'), 'last_name' => $value('lastname'), 'company_name' => $value('companyname'),
            'email' => $value('email'), 'phone' => $value('phonenumber'), 'tax_id' => $value('tax_id', 50),
            'address1' => $value('address1'), 'address2' => $value('address2'), 'city' => $value('city'), 'state' => $value('state'),
            'postcode' => $value('postcode'), 'country' => $country === null ? null : EuVat::iso($country),
            // The mails it takes, as WHMCS sent them: none ticked is none.
            'email_preferences' => json_encode($kinds),
        ];
    }

    /**
     * The Ekdosi contact it is, imported already: linked by legacy_contact_id, else the account's only non-primary
     * contact with its email and company name. None: its own contact.
     *
     * @param  array<int, array{0: ?string, 1: ?string}>  $legacy  tblcontacts.id => the Ekdosi contact's company and email, as imported
     */
    private function ekdosiContact(int $client, int $whmcsId, array $fields, array $legacy): ?Contact
    {
        $query = fn () => Contact::query()->where('client_id', $client)->whereNull('primary_of_client_id');
        if (isset($legacy[$whmcsId])) {
            [$company, $email] = $legacy[$whmcsId];
            $found = $query()->where('company_name', $company)->when($email !== null, fn ($q) => $q->where('email', $email))->get();
            if ($found->count() === 1) {
                return $found->first();
            }
        }
        if ($fields['email'] === null || $fields['company_name'] === null) {
            return null;
        }
        // Compared here, not in SQL: SQLite's LOWER() leaves Greek as it is.
        $fold = fn (?string $text) => mb_strtolower(trim((string) $text));
        $found = $query()->whereNotNull('email')->whereNotNull('company_name')->get()
            ->filter(fn (Contact $contact) => $fold($contact->email) === $fold($fields['email']) && $fold($contact->company_name) === $fold($fields['company_name']));

        return $found->count() === 1 ? $found->first() : null;
    }

    /** @return array<int, array{0: ?string, 1: ?string}> tblcontacts.id => its Ekdosi contact's company and email */
    private function legacyLinks(): array
    {
        try {
            $rows = $this->whmcs->query('SELECT legacy_contact_id, company_name, email FROM mod_ekdosi_contacts WHERE legacy_contact_id > 0')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return []; // no Ekdosi plugin
        }
        $links = [];
        foreach ($rows as $row) {
            $text = fn (string $key) => ($t = trim((string) ($row[$key] ?? ''))) === '' ? null : $t;
            $links[(int) $row['legacy_contact_id']] = [$text('company_name'), $text('email')];
        }

        return $links;
    }

    private static function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, '0000') ? null : $value;
    }
}
