<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A service's related contact (decided 2026-10-14, docs/accounts.md 3b): a contact of its account it concerns (a domain's
 * owner, a site's company). Information only for now: the invoice is the account's, as in WHMCS; who is billed is the
 * Ekdosi integration's, later (myip).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('client_id')->constrained('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
        });
    }
};
