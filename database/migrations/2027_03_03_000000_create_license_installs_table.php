<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the licensed software says about itself on /licensing/v1/check (docs/licensing.md, «Τηλεμετρία»): one row
 * per license, address, product and install, written after the answer. Who upgraded, who stays behind, who went back
 * (a downgrade), more installs than the license should have, a clock far from ours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_installs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45);
            // '' when not sent: part of the unique key, which treats nulls as all different.
            $table->string('product', 16)->default('');
            $table->string('install_id', 64)->default('');
            $table->string('version', 40)->nullable();
            $table->dateTime('build_time')->nullable();
            $table->string('commit', 40)->nullable();
            $table->string('binary_sha256', 64)->nullable();
            $table->string('os', 32)->nullable();
            $table->string('arch', 16)->nullable();
            // The client's clock minus ours, in seconds, at its last check.
            $table->integer('clock_skew')->nullable();
            $table->boolean('licensed')->nullable();
            $table->unsignedInteger('failures')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->unsignedInteger('checks')->default(0);
            // The version before the last change, and when the build went back in time.
            $table->string('previous_version', 40)->nullable();
            $table->dateTime('downgraded_at')->nullable();
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->unique(['license_id', 'ip', 'product', 'install_id']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_installs');
    }
};
