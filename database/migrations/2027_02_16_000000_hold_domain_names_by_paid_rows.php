<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who holds a domain name (owner 2026-11-08, docs/domains.md D-25): only a row that is active, in grace or redemption,
 * or a pending registration or transfer that is paid (its order line paid, free as ordered, or sent to its registrar:
 * `name_held_at`). An expired row or an unpaid order no longer keeps the name from another customer, as in WHMCS.
 *
 * `live_fqdn` (unique) is that rule in the database, so two rows never hold one name whatever writes them. SQLite cannot
 * add a stored generated column to a table: there it is virtual (indexed all the same). Its old rows: a pending one
 * holds once its order is paid or it was sent (backfilled); every other row as its status says.
 */
return new class extends Migration
{
    private const HOLDING = "status IN ('active','grace','redemption') OR (status IN ('pending_register','pending_transfer') AND name_held_at IS NOT NULL)";

    private const NOT_ENDED = "status NOT IN ('transferred_away','cancelled','deleted')";

    public function up(): void
    {
        Schema::table('domains', function ($table) {
            $table->timestamp('name_held_at')->nullable()->after('status');
        });
        $this->backfill();
        $this->define('CASE WHEN ' . self::HOLDING . ' THEN fqdn ELSE NULL END');
    }

    public function down(): void
    {
        // The old rule (every row not ended holds) cannot take two rows of one name the new one allows: listed, not lost.
        $twice = DB::table('domains')->whereRaw(self::NOT_ENDED)->groupBy('fqdn')->havingRaw('COUNT(*) > 1')->pluck('fqdn');
        if ($twice->isNotEmpty()) {
            throw new RuntimeException("{$twice->count()} name(s) have more than one domain row not ended (an expired one, an unpaid order): "
                . 'cancel all but one of each before rolling this migration back.');
        }
        $this->define('CASE WHEN ' . self::NOT_ENDED . ' THEN fqdn ELSE NULL END');
        // SQLite: dropped in place, never by rebuilding the table (that drops the generated live_fqdn).
        DB::getDriverName() === 'sqlite' ? DB::statement('ALTER TABLE domains DROP COLUMN name_held_at')
            : Schema::table('domains', fn ($table) => $table->dropColumn('name_held_at'));
    }

    /** A pending row paid already (its register/transfer line on a paid invoice), free as ordered, or sent: it holds. */
    private function backfill(): void
    {
        $pending = DB::table('domains')->whereIn('status', ['pending_register', 'pending_transfer'])->get(['id', 'meta', 'updated_at']);
        foreach ($pending as $row) {
            $meta = is_string($row->meta) ? (json_decode($row->meta, true) ?: []) : [];
            $lines = DB::table('invoice_items')->where('reference_type', 'App\\Models\\Domain')->where('reference_id', $row->id)
                ->whereIn('kind', ['register', 'transfer']);
            $paid = (clone $lines)->whereExists(fn ($q) => $q->from('invoices')->whereColumn('invoices.id', 'invoice_items.invoice_id')
                ->where('invoices.status', 'paid'))->exists();
            $free = ($meta['free_order'] ?? false) === true && !(clone $lines)->exists();
            if ($paid || $free || !empty($meta['sent_at'])) {
                DB::table('domains')->where('id', $row->id)->update(['name_held_at' => $row->updated_at ?? now()]);
            }
        }
    }

    /** `live_fqdn` = fqdn when $case says so, with its unique index, on both drivers. */
    private function define(string $case): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS domains_live_fqdn_unique');
            DB::statement('ALTER TABLE domains DROP COLUMN live_fqdn');
            DB::statement("ALTER TABLE domains ADD COLUMN live_fqdn varchar GENERATED ALWAYS AS ({$case}) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX domains_live_fqdn_unique ON domains (live_fqdn)');

            return;
        }
        DB::statement('ALTER TABLE domains DROP INDEX domains_live_fqdn_unique, DROP COLUMN live_fqdn');
        DB::statement("ALTER TABLE domains ADD COLUMN live_fqdn varchar(255) GENERATED ALWAYS AS ({$case}) STORED AFTER status,"
            . ' ADD UNIQUE KEY domains_live_fqdn_unique (live_fqdn)');
    }
};
