<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who of the staff has a ticket open, and who writes in it (docs/tickets.md §6ν): one row per open page of the ticket,
 * kept by its heartbeat and read only while it beats. Never shown to the customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_presences', function (Blueprint $table) {
            $table->id();
            // The Livewire component's id: two tabs of the same member are two rows.
            $table->string('page', 64)->unique();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->timestamp('alive_at');
            $table->timestamp('viewing_at')->nullable();
            $table->timestamp('typed_at')->nullable();
            $table->boolean('has_draft')->default(false);
            $table->index(['ticket_id', 'alive_at']);
            $table->index('alive_at');
        });
    }
};
