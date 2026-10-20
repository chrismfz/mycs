<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An account's notes, staff only (ROADMAP «Επόμενα» 2, 2026-10-20), as WHMCS's «Notes» tab (`tblnotes`: author, date,
 * sticky) and its profile's «Admin Notes» (`tblclients.notes`, brought by the importer as a note). A sticky one is shown
 * on the client's Summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->text('body');
            $table->boolean('sticky')->default(false);
            $table->timestamps();
            $table->index(['client_id', 'sticky']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notes');
    }
};
