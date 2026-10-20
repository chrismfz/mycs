<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Customer groups with a discount (ROADMAP 5, owner 2026-10-20), as WHMCS's tblclientgroups: a name, a colour and a
// percentage off its members' services (in their price, decided 2026-10-20).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('colour', 7)->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->timestamps();
        });
        Schema::table('clients', function (Blueprint $table) {
            // No foreign key: on SQLite dropping one rebuilds `clients`, which cascades to its logins' grants (a rollback
            // of 2026_10_28 then fails). A deleted group's members leave it first (ClientGroupObserver::deleting).
            $table->unsignedBigInteger('client_group_id')->nullable()->index()->after('id');
        });
        // The group discount its price holds (repriced from it, never guessed: reviews of #272), and staff's «keep this
        // price» (it never follows a group).
        Schema::table('services', function (Blueprint $table) {
            $table->decimal('group_percent', 5, 2)->default(0)->after('price');
            $table->boolean('price_locked')->default(false)->after('group_percent');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['group_percent', 'price_locked']);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['client_group_id']);
            $table->dropColumn('client_group_id');
        });
        Schema::dropIfExists('client_groups');
    }
};
