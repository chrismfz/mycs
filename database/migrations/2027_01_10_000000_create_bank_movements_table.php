<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The banks' alert emails, read without touching the mailbox (bank:alerts): each credit and debit of the
        // accounts, the balance after it, and what staff (later the matching) made of a credit.
        Schema::create('bank_movements', function (Blueprint $table) {
            $table->id();
            $table->string('bank', 20);
            $table->string('direction', 10);
            $table->decimal('amount', 17, 2);
            $table->decimal('balance', 17, 2)->nullable();
            $table->string('reason', 500)->default('');
            $table->timestamp('executed_at')->nullable();
            // The mail: read once, wherever it is moved to (another folder keeps its Message-ID).
            $table->string('message_key', 64)->unique();
            $table->string('message_id')->nullable();
            $table->string('folder')->nullable();
            $table->boolean('authentic')->default(false);
            $table->string('authenticity', 64)->nullable();
            // pending: for staff; acknowledged: staff saw to it; ignored: a configured reference (card settlements);
            // history: already in the mailbox when the reading started (never matched to an invoice).
            $table->string('status', 20)->default('pending');
            $table->foreignId('acknowledged_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['direction', 'status']);
            $table->index(['bank', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_movements');
    }
};
