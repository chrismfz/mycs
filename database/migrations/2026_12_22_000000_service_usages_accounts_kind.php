<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a usage's «accounts» are: a reseller's hosting accounts, or mailboxes (mailcow). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_usages', function (Blueprint $table) {
            $table->string('accounts_kind', 16)->default('accounts')->after('accounts_limit');
        });
    }

    public function down(): void
    {
        Schema::table('service_usages', function (Blueprint $table) {
            $table->dropColumn('accounts_kind');
        });
    }
};
