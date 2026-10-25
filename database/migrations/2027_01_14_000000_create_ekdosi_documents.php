<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Ekdosi bridge, E1 (docs/ekdosi-bridge.md).
 *
 * - `ekdosi_documents`: the fiscal documents Ekdosi issued from an invoice, as it wrote them back (its MARK at AADE, its
 *   code such as ΤΠΥ423, active or cancelled, its PDF). An invoice split between recipients has several; one with any is
 *   no longer in the feed.
 * - `domains.contact_id`: the domain's related contact, as a service's (docs/accounts.md 3b): who its lines are invoiced
 *   to by Ekdosi. Not one of its roles at the registrar, so setting it never changes anything there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ekdosi_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('mark', 32)->unique(); // AADE's MARK, a 15-digit number, kept as text
            $table->string('code', 40)->nullable(); // Ekdosi's series and number, e.g. ΤΠΥ423
            $table->string('state', 12)->default('active'); // active | cancelled
            $table->string('pdf_url', 1000)->nullable();
            $table->timestamps();
            $table->index('invoice_id');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('client_id')->constrained('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
        });
        Schema::dropIfExists('ekdosi_documents');
    }
};
