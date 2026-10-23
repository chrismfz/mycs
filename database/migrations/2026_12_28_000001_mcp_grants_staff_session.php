<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The staff session (its ulid) a consent was given in: ending that session revokes what it approved. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_oauth_codes', function (Blueprint $table) {
            $table->string('staff_session', 26)->nullable();
        });
        Schema::table('mcp_oauth_grants', function (Blueprint $table) {
            $table->string('staff_session', 26)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('mcp_oauth_grants', function (Blueprint $table) {
            $table->dropIndex(['staff_session']);
            $table->dropColumn('staff_session');
        });
        Schema::table('mcp_oauth_codes', function (Blueprint $table) {
            $table->dropColumn('staff_session');
        });
    }
};
