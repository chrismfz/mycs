<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHMCS «Retired» as its own flag (adversarial review of #216, ADV-1): a retired product is not ordered any more. It was
 * «hidden with stock 0», but every way a service ends gives its stock back, so the first old customer to cancel put
 * it on sale again. The products the importer brought as retired (hidden, stock 0, from WHMCS) become retired; a
 * product of ours that is hidden and sold out stays only that. A re-run of the importer sets it from WHMCS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('retired')->default(false);
        });
        DB::table('products')->where('hidden', true)->where('stock', 0)->whereNotNull('whmcs_id')->update(['retired' => true]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('retired');
        });
    }
};
