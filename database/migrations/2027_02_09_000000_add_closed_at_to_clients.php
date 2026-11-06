<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A closed account (WHMCS «Closed», WG-8): its logins cannot sign in unless another account of theirs is open, and it
 * takes no new orders. Staff reopen it by clearing it. WHMCS's «Inactive» (no active service) is not stored: it is
 * what the account's services say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('no_overdue_reminders');
        });
    }
};
