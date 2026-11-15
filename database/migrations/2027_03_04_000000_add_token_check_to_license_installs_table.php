<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the client's check of its last signed token said (docs/licensing.md, «Signed token»): ok, bad, or none (it had
 * none). In shadow: the token decides nothing yet, this says whether it would have worked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_installs', function (Blueprint $table) {
            $table->string('token_check', 8)->nullable()->after('failures');
        });
    }

    public function down(): void
    {
        Schema::table('license_installs', function (Blueprint $table) {
            $table->dropColumn('token_check');
        });
    }
};
