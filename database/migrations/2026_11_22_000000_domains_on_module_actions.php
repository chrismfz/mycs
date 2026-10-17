<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A domain's registrar action that failed or waits for staff (docs/domains.md D-15, D5b-2) among the Pending Module
 * Actions: its domain and the paid invoice line it is for, instead of a service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_actions', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->change();
            $table->foreignId('domain_id')->nullable()->after('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->after('domain_id')->constrained()->nullOnDelete();
            $table->index(['domain_id', 'action', 'status']);
        });
    }

    public function down(): void
    {
        // The keys first (an index a foreign key uses is not dropped before it, MariaDB 1553), then the index and columns.
        Schema::table('module_actions', function (Blueprint $table) {
            $table->dropForeign(['invoice_item_id']);
            $table->dropForeign(['domain_id']);
        });
        Schema::table('module_actions', function (Blueprint $table) {
            $table->dropIndex(['domain_id', 'action', 'status']);
            $table->dropColumn(['invoice_item_id', 'domain_id']);
        });
    }
};
