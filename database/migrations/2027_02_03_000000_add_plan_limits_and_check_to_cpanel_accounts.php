<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hosting Accounts, the package and limits check (ROADMAP, Admin): what the account's package says (disk and bandwidth, as
 * WHM's getpkginfo told at the last read: 0 is «no limit», none is «not read») and what the check found against what we bill
 * (AccountCheck). The check is kept with the account, so the list filters on it in SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cpanel_accounts', function (Blueprint $table) {
            $table->unsignedInteger('plan_disk_mb')->nullable();
            $table->unsignedInteger('plan_bandwidth_mb')->nullable();
            $table->string('limits_check', 32)->nullable()->index();
            $table->integer('limits_check_mb')->nullable();
            $table->timestamp('limits_checked_at')->nullable();
        });
    }
};
