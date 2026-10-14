<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A service's own server, and server groups (WHMCS's tblhosting.server, tblservergroups): at myip 657 cPanel accounts
 * live on 6 servers and a product's older services stay on the server they were created on, so the server is the
 * service's, not only the product's. A product keeps its server (its module and settings); with a group, new accounts
 * go to the group's server its fill rule picks, within each server's max accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->unsignedInteger('max_accounts')->nullable()->after('enabled'); // servers: null = no limit
        });

        Schema::create('server_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('fill_type', 16)->default('least_full'); // least_full | in_order (WHMCS: «fill active server»)
            $table->timestamps();
        });

        Schema::create('server_group_server', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained('extensions')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unique(['server_group_id', 'server_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('server_group_id')->nullable()->after('server_id')->constrained()->nullOnDelete();
        });

        Schema::table('services', function (Blueprint $table) {
            // A server with accounts on it is not deleted (restrict): move or end them first.
            $table->foreignId('server_id')->nullable()->after('product_id')->constrained('extensions')->restrictOnDelete();
        });

        $this->backfill();
    }

    /** Every service so far lives on its product's server. */
    public function backfill(): void
    {
        DB::table('services')->whereNull('server_id')->whereIn('product_id', DB::table('products')->whereNotNull('server_id')->select('id'))
            ->update(['server_id' => DB::raw('(SELECT products.server_id FROM products WHERE products.id = services.product_id)')]);
    }

    public function down(): void
    {
        // SQLite rebuilds a table to drop a column: the services' key to products would refuse it.
        Schema::disableForeignKeyConstraints();
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('server_id');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('server_group_id');
        });
        Schema::dropIfExists('server_group_server');
        Schema::dropIfExists('server_groups');
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropColumn('max_accounts');
        });
        Schema::enableForeignKeyConstraints();
    }
};
