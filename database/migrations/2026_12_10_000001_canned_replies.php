<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Predefined replies in categories (docs/tickets.md §6ι), WHMCS's brought by the ticket import (whmcs_id). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canned_reply_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedInteger('whmcs_id')->nullable()->unique();
            $table->char('whmcs_hash', 64)->nullable(); // what the import last wrote: anything else was edited here
            $table->timestamps();
        });
        Schema::create('canned_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canned_reply_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('whmcs_id')->nullable()->unique();
            $table->char('whmcs_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_replies');
        Schema::dropIfExists('canned_reply_categories');
    }
};
