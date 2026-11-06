<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hosting Accounts, what staff say about the check (owner 2026-11-04): a temporary extra («a little more space for a while»),
 * expected until its date and then a reminder to take it back, and a finding approved with a note, quiet while what was
 * approved stays the same (a free extra by its amount: approved_mb) (AccountCheck). Nothing here changes the server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cpanel_accounts', function (Blueprint $table) {
            $table->unsignedInteger('temporary_extra_mb')->nullable();
            $table->date('temporary_extra_until')->nullable();
            $table->string('temporary_extra_note')->nullable();
            $table->foreignId('temporary_extra_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('approved_check', 32)->nullable();
            $table->string('approved_state', 64)->nullable();
            $table->integer('approved_mb')->nullable();
            $table->string('approved_note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
        });
    }
};
