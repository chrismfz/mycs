<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WHMCS's store addresses of a product, old ones too (tblproducts_slugs: a renamed product keeps its old links): the
     * 301 map of the cut-over sends /store/{group}/{slug} to the product here (LegacyWhmcsController).
     */
    public function up(): void
    {
        Schema::create('whmcs_product_slugs', function (Blueprint $table) {
            $table->id();
            $table->string('group_slug');
            $table->string('slug');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['group_slug', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whmcs_product_slugs');
    }
};
