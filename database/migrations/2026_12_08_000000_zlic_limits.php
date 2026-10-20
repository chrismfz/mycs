<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// zlic.php's counters (#254): counted atomically in the database, not in the file cache (whose increment loses hits).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zlic_limits', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedBigInteger('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zlic_limits');
    }
};
