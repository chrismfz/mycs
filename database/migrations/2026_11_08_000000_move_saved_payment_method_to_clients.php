<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The saved payment method that pays renewals is the account's, not each service's (decision 6, 2026-10-15; WHMCS and
 * Blesta): a grouped renewal invoice (PR C1) bills several services, and one method pays it. «Auto-charge» off stops it
 * (WHMCS `tblclients.disableautocc`). A gateway subscription stays the exception: it pays its own invoice.
 *
 * Each account's default is the method most of its running services had (the newest on a tie); one the customer removed is
 * not taken.
 *
 * SQLite (tests) gets no foreign keys here and keeps the unused `services.billing_agreement_id`: adding or dropping a
 * foreign key rebuilds the table there, and inside a transaction (where `PRAGMA foreign_keys = OFF` does nothing) the
 * rebuild of `clients` or `services` deletes their children by cascade. Nothing reads the old column.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        Schema::table('clients', function (Blueprint $table) use ($sqlite) {
            $table->unsignedBigInteger('default_billing_agreement_id')->nullable()->after('separate_invoices');
            $table->boolean('auto_charge')->default(true)->after('default_billing_agreement_id');
            if (!$sqlite) {
                $table->foreign('default_billing_agreement_id')->references('id')->on('billing_agreements')->nullOnDelete();
            }
        });

        $used = DB::table('services')
            ->join('billing_agreements', 'billing_agreements.id', '=', 'services.billing_agreement_id')
            ->whereColumn('billing_agreements.client_id', 'services.client_id')
            ->whereNull('billing_agreements.deleted_at')
            ->whereIn('services.status', ['active', 'suspended'])
            ->groupBy('services.client_id', 'services.billing_agreement_id')
            ->orderByDesc(DB::raw('COUNT(*)'))->orderByDesc('services.billing_agreement_id')
            ->get(['services.client_id', 'services.billing_agreement_id']);
        foreach ($used->unique('client_id') as $row) {
            DB::table('clients')->where('id', $row->client_id)->update(['default_billing_agreement_id' => $row->billing_agreement_id]);
        }

        if (!$sqlite) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropConstrainedForeignId('billing_agreement_id');
            });
        }
    }

    public function down(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        if (!Schema::hasColumn('services', 'billing_agreement_id')) {
            Schema::table('services', function (Blueprint $table) {
                $table->foreignId('billing_agreement_id')->nullable()->constrained('billing_agreements')->nullOnDelete();
            });
        }
        // Back on the running services of accounts that charge automatically.
        foreach (DB::table('clients')->whereNotNull('default_billing_agreement_id')->where('auto_charge', true)->get(['id', 'default_billing_agreement_id']) as $client) {
            DB::table('services')->where('client_id', $client->id)->whereIn('status', ['active', 'suspended'])
                ->update(['billing_agreement_id' => $client->default_billing_agreement_id]);
        }
        Schema::table('clients', function (Blueprint $table) use ($sqlite) {
            if (!$sqlite) {
                $table->dropForeign(['default_billing_agreement_id']);
            }
            $table->dropColumn(['default_billing_agreement_id', 'auto_charge']);
        });
    }
};
