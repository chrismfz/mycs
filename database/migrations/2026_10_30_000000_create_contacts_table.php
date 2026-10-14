<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The account's contacts (docs/accounts.md, PR 3a): WHMCS `tblcontacts` and Ekdosi's `mod_ekdosi_contacts` as one
 * table, with the fields invoicing (Ekdosi) and the domain registries need. The primary contact is the account's
 * details, as `tblclients` holds them in WHMCS: exactly one per account (unique `primary_of_client_id`).
 *
 * Existing accounts get their primary contact from the owner (name, email) and the account's identity properties
 * (phone, company_name, address, address2, city, state, zip, country), which then leave `properties`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** The identity properties that become the primary contact's columns, and their column. */
    private const PROPERTIES = [
        'phone' => 'phone', 'company_name' => 'company_name', 'address' => 'address1', 'address2' => 'address2',
        'city' => 'city', 'state' => 'state', 'zip' => 'postcode', 'country' => 'country',
    ];

    public function up(): void
    {
        // Re-runnable: a run stopped half way (no transaction for DDL) goes on from where it was.
        if (!Schema::hasTable('contacts')) {
            Schema::create('contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                // = client_id for the primary contact, NULL otherwise: one primary per account (as client_user's owner).
                $table->unsignedBigInteger('primary_of_client_id')->nullable()->unique();
                $table->string('type', 20)->default('individual'); // individual | business
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('company_name')->nullable();
                // The Greek ΑΦΜ or a foreign tax id; the EU VAT number (VIES) apart, as the bridge's gr_vatno / vies_vatno.
                $table->string('tax_id', 50)->nullable();
                $table->string('vat_number', 50)->nullable();
                $table->string('tax_office')->nullable(); // ΔΟΥ
                $table->string('activity')->nullable();
                $table->string('address1')->nullable();
                $table->string('address2')->nullable();
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                $table->string('postcode')->nullable(); // the properties were up to 255: nothing is cut
                $table->string('country', 2)->nullable(); // ISO 3166-1 alpha-2
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->text('notes')->nullable();
                // What an invoice to this contact is: invoice (τιμολόγιο), receipt (απόδειξη), none. NULL = not chosen.
                $table->string('document_type', 20)->nullable();
                // Which mails the contact receives (tblcontacts): general, invoice, support, product, domain, affiliate.
                $table->json('email_preferences')->nullable();
                // A contact in use is archived, not deleted.
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
                $table->index(['client_id', 'archived_at']);
            });
        }

        $countries = (array) config('app.countries', []);
        $owners = DB::table('client_user')->whereNotNull('owner_of_client_id')->join('users', 'users.id', '=', 'client_user.user_id')
            ->pluck('users.id', 'client_user.client_id');
        DB::table('clients')->orderBy('id')->select('id', 'created_at')
            ->whereNotExists(fn ($query) => $query->from('contacts')->whereColumn('contacts.primary_of_client_id', 'clients.id'))
            ->chunk(500, function ($clients) use ($countries, $owners) {
                $ids = $clients->pluck('id')->all();
                $properties = DB::table('properties')->where('model_type', 'App\\Models\\Client')->whereIn('model_id', $ids)
                    ->whereIn('key', array_keys(self::PROPERTIES))->get(['model_id', 'key', 'value'])->groupBy('model_id');
                $users = DB::table('users')->whereIn('id', $owners->only($ids)->values()->all())->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id');
                $rows = [];
                foreach ($clients as $client) {
                    $user = $users->get($owners->get($client->id));
                    $row = [
                        'client_id' => $client->id, 'primary_of_client_id' => $client->id, 'type' => 'individual',
                        'first_name' => $user?->first_name, 'last_name' => $user?->last_name, 'email' => $user?->email,
                        'notes' => null, 'created_at' => $client->created_at ?? now(), 'updated_at' => now(),
                    ];
                    foreach (array_values(self::PROPERTIES) as $column) {
                        $row[$column] = null;
                    }
                    foreach ($properties->get($client->id, collect()) as $property) {
                        $value = trim((string) $property->value);
                        $row[self::PROPERTIES[$property->key]] = $value === '' ? null : $value;
                    }
                    $legacy = $row['country'];
                    $row['country'] = self::isoCountry($legacy, $countries);
                    if ($legacy !== null && $row['country'] === null) {
                        // Kept where staff see it, not lost: the account has no VAT country until it is set.
                        $row['notes'] = 'Country (before the contacts): ' . $legacy;
                    }
                    $row['type'] = $row['company_name'] !== null ? 'business' : 'individual';
                    $rows[] = $row;
                }
                DB::table('contacts')->insert($rows);
            });

        DB::table('properties')->where('model_type', 'App\\Models\\Client')->whereIn('key', array_keys(self::PROPERTIES))->delete();
        DB::table('custom_properties')->where('model', 'App\\Models\\Client')->whereIn('key', array_keys(self::PROPERTIES))->delete();
    }

    /**
     * A stored country (a name, in any case, or a code) as its ISO code; null when it is none.
     *
     * @param  array<string, string>  $countries
     */
    private static function isoCountry(?string $value, array $countries): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $aliases = ['UK' => 'GB', 'EL' => 'GR'];
        $code = strtoupper($value);
        if (isset($aliases[$code])) {
            return $aliases[$code];
        }
        if (strlen($code) === 2 && $code !== '' && isset($countries[$code])) {
            return $code;
        }
        foreach ($countries as $iso => $name) {
            if ($iso !== '' && mb_strtolower($name) === mb_strtolower($value)) {
                return $iso;
            }
        }

        return null;
    }

    public function down(): void
    {
        // The identity properties come back from the primary contacts (custom fields as the seeder had them).
        $definitions = [
            'phone' => ['Phone', 'string', 1, 0], 'company_name' => ['Company Name', 'string', 0, 1], 'address' => ['Address', 'string', 1, 1],
            'address2' => ['Address 2', 'string', 0, 0], 'city' => ['City', 'string', 1, 1], 'state' => ['State', 'string', 1, 1],
            'zip' => ['ZIP', 'string', 1, 1], 'country' => ['Country', 'select', 1, 1],
        ];
        $countries = (array) config('app.countries', []);
        $ids = [];
        foreach ($definitions as $key => [$name, $type, $required, $onInvoice]) {
            DB::table('custom_properties')->insertOrIgnore([
                'key' => $key, 'name' => $name, 'model' => 'App\\Models\\Client', 'type' => $type, 'non_editable' => 0,
                'required' => $required, 'show_on_invoice' => $onInvoice, 'validation' => 'string|max:255',
                'allowed_values' => $key === 'country' ? json_encode(array_values(array_slice($countries, 1))) : null,
            ]);
            $ids[$key] = DB::table('custom_properties')->where('model', 'App\\Models\\Client')->where('key', $key)->value('id');
        }
        DB::table('contacts')->whereNotNull('primary_of_client_id')->orderBy('id')->chunk(500, function ($contacts) use ($definitions, $ids, $countries) {
            $rows = [];
            foreach ($contacts as $contact) {
                foreach (self::PROPERTIES as $key => $column) {
                    $value = $contact->{$column};
                    if ($key === 'country' && $value !== null) {
                        $value = $countries[$value] ?? $value;
                    }
                    if ($value !== null && $value !== '') {
                        $rows[] = ['model_type' => 'App\\Models\\Client', 'model_id' => $contact->client_id, 'key' => $key,
                            'name' => $definitions[$key][0], 'value' => $value, 'custom_property_id' => $ids[$key]];
                    }
                }
            }
            if ($rows !== []) {
                DB::table('properties')->insertOrIgnore($rows);
            }
        });

        Schema::dropIfExists('contacts');
    }
};
