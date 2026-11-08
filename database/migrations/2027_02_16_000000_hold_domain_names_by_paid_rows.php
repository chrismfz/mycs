<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who holds a domain name (owner 2026-11-08, docs/domains.md D-25): only a row that is active, in grace or redemption,
 * or a pending registration or transfer that is paid (its order line paid, free as ordered, or sent to its registrar:
 * `name_held_at`), or an expired row until its registry's recovery window ends (expiry + grace + redemption, owner
 * 2026-11-08) or whose renewal is paid and waiting for its registrar (`name_held_at` too; the daily domains_superseded
 * step releases it). An expired row past its window or an unpaid order no longer keeps the name from another customer,
 * as in WHMCS.
 *
 * `live_fqdn` (unique) is that rule in the database, so two rows never hold one name whatever writes them. SQLite cannot
 * add a stored generated column to a table: there it is virtual (indexed all the same). Its old rows: a pending one
 * holds once its order is paid or it was sent, an expired one inside its window or while its renewal is paid
 * (backfilled); every other row as its status says.
 */
return new class extends Migration
{
    private const HOLDING = "status IN ('active','grace','redemption') OR (status IN ('pending_register','pending_transfer','expired') AND name_held_at IS NOT NULL)";

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
        // An expired row with a renewal paid and not applied yet (ADV-DNH-1): its customer paid first, it holds.
        DB::table('domains')->where('status', 'expired')->whereExists(fn ($q) => $q->from('invoice_items')
            ->whereColumn('invoice_items.reference_id', 'domains.id')->where('invoice_items.reference_type', 'App\\Models\\Domain')
            ->whereNull('invoice_items.kind')->whereNotNull('invoice_items.period_start')->whereNotNull('invoice_items.period_end')
            ->whereNull('invoice_items.applied_at')->whereExists(fn ($i) => $i->from('invoices')
            ->whereColumn('invoices.id', 'invoice_items.invoice_id')->where('invoices.status', 'paid')))
            ->update(['name_held_at' => now()]);
        // An expired row its registry may still give back (owner 2026-11-08): it holds until its recovery window ends
        // (expiry + grace + redemption, Domain::recoveryEndsOn). Read generously here (the latest of its registrar's dates,
        // its days, 30 days): the cron's domains_superseded step releases it by its exact window the next night. Never a
        // second holder of a name: the latest expiry first, and none where a row holds it already.
        $held = DB::table('domains')->whereRaw(self::HOLDING)->pluck('fqdn')->flip()->all();
        $expired = DB::table('domains')->leftJoin('domain_tlds', 'domain_tlds.id', '=', 'domains.domain_tld_id')
            ->where('domains.status', 'expired')->whereNull('domains.name_held_at')->whereNotNull('domains.expires_at')
            ->orderByDesc('domains.expires_at')->orderByDesc('domains.id')
            ->get(['domains.id', 'domains.fqdn', 'domains.expires_at', 'domains.grace_ends_on', 'domains.redemption_ends_on',
                'domains.grace_days_override', 'domains.redemption_days_override', 'domain_tlds.grace_days', 'domain_tlds.redemption_days']);
        foreach ($expired as $row) {
            $expiry = Carbon::parse($row->expires_at)->startOfDay();
            $days = max(0, (int) ($row->grace_days_override ?? $row->grace_days)) + max(0, (int) ($row->redemption_days_override ?? $row->redemption_days));
            $last = collect([$row->grace_ends_on, $row->redemption_ends_on])->filter()->map(fn ($date) => Carbon::parse($date)->startOfDay())
                ->push($expiry->copy()->addDays(max($days, 30)))->max();
            if (isset($held[$row->fqdn]) || $last->lessThan(today())) {
                continue;
            }
            DB::table('domains')->where('id', $row->id)->update(['name_held_at' => now()]);
            $held[$row->fqdn] = true;
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
