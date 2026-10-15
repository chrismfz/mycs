<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EU VAT (PR G, docs/eu-vat.md).
 * - contacts.vat_status: what VIES last said about vat_checked_number (pending: not known yet, VAT is charged).
 * - vat_checks: each check, with the consultation number: the proof that a reverse-charged sale's number was valid.
 * - invoice_items.tax_reason: why a line has no VAT (reverse_charge, outside_eu, exempt, not_taxable), for the
 *   proforma's note and for Ekdosi's exemption category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('vat_status', 16)->nullable()->after('vat_number');
            $table->string('vat_checked_number', 50)->nullable()->after('vat_status');
            $table->timestamp('vat_checked_at')->nullable()->after('vat_checked_number');
        });

        Schema::create('vat_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('vat_number', 50);
            // valid / invalid / unavailable (VIES gave no answer), or set by staff.
            $table->string('result', 16);
            $table->string('source', 16)->default('vies');
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->string('name')->nullable();
            $table->text('address')->nullable();
            $table->string('request_identifier')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index(['contact_id', 'checked_at']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('tax_reason', 20)->nullable()->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('tax_reason');
        });
        Schema::dropIfExists('vat_checks');
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['vat_status', 'vat_checked_number', 'vat_checked_at']);
        });
    }
};
