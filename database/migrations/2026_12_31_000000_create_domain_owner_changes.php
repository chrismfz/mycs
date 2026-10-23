<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A .gr domain's change of holder (G8-5b, owner 2026-10-23): a name change or a transfer to another holder, charged at
 * one price, sent to the registry once paid, never recalled; its signed declaration kept with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_owner_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);                   // ChangesOwner::NAME / OWNER
            $table->foreignId('contact_id')->constrained()->restrictOnDelete(); // the new holder
            $table->foreignId('move_to_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('declaration_path')->nullable(); // the signed declaration, on the private disk
            $table->string('declaration_name')->nullable();
            $table->string('status', 24)->index();         // see DomainOwnerChange::STATUS_*
            $table->boolean('free')->default(false);      // sent without an invoice (staff, «send now»)
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->text('result')->nullable();           // what the registry said, or why not
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_owner_changes');
    }
};
