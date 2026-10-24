<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two choices of the account for the fiscal invoicing in Ekdosi (owner 2026-10-24), as Ekdosi has them («Ignore invoicing», «Immediate
 * invoicing»). Staff only; kept now, read by the Ekdosi bridge when it comes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('ignore_invoicing')->default(false);
            $table->boolean('immediate_invoicing')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['ignore_invoicing', 'immediate_invoicing']);
        });
    }
};
