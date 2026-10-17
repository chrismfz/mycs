<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordering domains (D8b, docs/domains.md): a cart item may be a domain (register or transfer: no product nor plan, its
 * name, TLD, years and registrant in `domain`, a transfer's EPP code apart, encrypted); a domain remembers the order it
 * came from and, for a transfer, the EPP code the customer gave (owner 2026-10-17: staff see it on the order, as in
 * WHMCS, to tell a wrong code; encrypted at rest as every secret, CLAUDE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->json('domain')->nullable()->after('checkout_config');
            $table->text('epp_code')->nullable()->after('domain');
        });
        Schema::table('domains', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            $table->text('epp_code')->nullable()->after('registrar_domain_id');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn('epp_code');
        });
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn(['domain', 'epp_code']);
        });
    }
};
