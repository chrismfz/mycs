<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The account's server in WHMCS (tblhosting.server, 0 for none), as imported: a service brought from WHMCS acts on
        // that server or on none, never on its product's (AUD4-1).
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedInteger('whmcs_server_id')->nullable()->after('server_id');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('whmcs_server_id');
        });
    }
};
