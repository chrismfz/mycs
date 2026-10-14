<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * nixpal licensing (packages/nixpal-licensing): the licenses zlic.php answers for, as WHMCS's Licensing Addon keeps them
 * (mod_licensing, mod_licensinglog). One license per service; its key, its bindings (IP is what is checked; domain and
 * directory are kept for information, docs/history/blesta-modules/licensing.md §3.3), reissues and status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('license_key', 191)->unique();
            $table->string('status', 16); // Active, Reissued, Suspended, Expired
            $table->text('valid_domain')->nullable();
            $table->text('valid_ip')->nullable();
            $table->text('valid_directory')->nullable();
            $table->unsignedInteger('reissues')->default(0);
            $table->dateTime('last_access')->nullable();
            $table->unsignedBigInteger('whmcs_id')->nullable()->unique(); // mod_licensing.id, for the importer
            // Beyond WHMCS: signs of life. last_access moves on every check (also failed ones, as WHMCS); these say when
            // it last answered Active, from where and from which client, and the last failure and why.
            $table->dateTime('last_ok_at')->nullable()->index();
            $table->string('last_seen_ip', 64)->nullable();
            $table->string('last_seen_client')->nullable(); // method and User-Agent: the GET (C++) clients still alive
            $table->dateTime('last_failure_at')->nullable();
            $table->string('last_failure')->nullable();
            $table->timestamps();
        });

        // Checks per license and day (ok / failed): a month's history without a row per check (588k a year).
        Schema::create('license_check_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('ok')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unique(['license_id', 'day']);
        });

        Schema::create('license_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain')->default('');
            $table->string('ip', 64)->default('');
            $table->string('directory')->default('');
            $table->string('message');
            $table->dateTime('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_check_days');
        Schema::dropIfExists('license_access_logs');
        Schema::dropIfExists('licenses');
    }
};
