<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's language (docs/accounts.md): the account's for its mails and PDFs (WHMCS's tblclients.language), a
 * login's for its pages (tblusers.language). Null: the instance's (app_language).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->string('language', 10)->nullable());
        Schema::table('users', fn (Blueprint $table) => $table->string('language', 10)->nullable());
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('language'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('language'));
    }
};
