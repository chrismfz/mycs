<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bulk license's billing (docs/zonecloud-bulk-license.md §6.0): each paid period, once over, closed with what it
 * really cost (the distinct IPs it had) against what was billed for it in advance; the difference billed on the next
 * renewal invoice, or given back as credit. A license's own tiers (agreed prices, Ε6) beside its product's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_usage_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            // The renewal line that billed it in advance.
            $table->foreignId('invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('ips');
            $table->decimal('actual', 12, 2);
            $table->decimal('billed', 12, 2);
            $table->decimal('adjustment', 12, 2);
            // Where a difference up went: a line of a later renewal invoice (null until then). One down is credit at once
            // (the account's credit history, cause usage_correction).
            $table->foreignId('adjustment_item_id')->nullable()->constrained('invoice_items')->nullOnDelete();
            $table->string('tiers', 255);
            $table->dateTime('closed_at');
            $table->timestamps();
            $table->unique(['license_id', 'period_start']);
        });
        Schema::table('licenses', function (Blueprint $table) {
            // «1=1.50» or «1=1.80, 51=1.50»: this service's price per IP, instead of its product's (Ε6).
            $table->string('tiers', 255)->nullable()->after('reissues');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', fn (Blueprint $table) => $table->dropColumn('tiers'));
        Schema::dropIfExists('license_usage_periods');
    }
};
