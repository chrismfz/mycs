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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('default_billing_agreement_id')->nullable()->after('separate_invoices')
                ->constrained('billing_agreements')->nullOnDelete();
            $table->boolean('auto_charge')->default(true)->after('default_billing_agreement_id');
        });

        $used = DB::table('services')
            ->join('billing_agreements', 'billing_agreements.id', '=', 'services.billing_agreement_id')
            ->whereColumn('billing_agreements.client_id', 'services.client_id')
            ->whereNull('billing_agreements.deleted_at')
            ->whereIn('services.status', ['active', 'suspended'])
            ->groupBy('services.client_id', 'services.billing_agreement_id')
            ->orderByDesc(DB::raw('COUNT(*)'))->orderByDesc('services.billing_agreement_id')
            ->get(['services.client_id', 'services.billing_agreement_id', DB::raw('COUNT(*) as uses')]);
        foreach ($used->unique('client_id') as $row) {
            DB::table('clients')->where('id', $row->client_id)->update(['default_billing_agreement_id' => $row->billing_agreement_id]);
        }

        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_agreement_id');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('billing_agreement_id')->nullable()->constrained('billing_agreements')->nullOnDelete();
        });
        // Back on the running recurring services of accounts that charge automatically.
        foreach (DB::table('clients')->whereNotNull('default_billing_agreement_id')->where('auto_charge', true)->get(['id', 'default_billing_agreement_id']) as $client) {
            DB::table('services')->where('client_id', $client->id)->whereIn('status', ['active', 'suspended'])
                ->update(['billing_agreement_id' => $client->default_billing_agreement_id]);
        }
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_billing_agreement_id');
            $table->dropColumn('auto_charge');
        });
    }
};
