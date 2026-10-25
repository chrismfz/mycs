<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WHMCS's «Exempt from auto suspend/terminate» and «Disable overdue notices» per client, and its client groups'
        // «Separate invoices» and «Exempt from suspend & terminate» (sweep of myip, 2026-10-25): a client's or its group's.
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('dunning_exempt')->default(false)->after('separate_invoices');
            $table->boolean('no_overdue_reminders')->default(false)->after('dunning_exempt');
        });
        Schema::table('client_groups', function (Blueprint $table) {
            $table->boolean('separate_invoices')->default(false)->after('discount_percent');
            $table->boolean('dunning_exempt')->default(false)->after('separate_invoices');
            $table->boolean('no_overdue_reminders')->default(false)->after('dunning_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['dunning_exempt', 'no_overdue_reminders']));
        Schema::table('client_groups', fn (Blueprint $table) => $table->dropColumn(['separate_invoices', 'dunning_exempt', 'no_overdue_reminders']));
    }
};
