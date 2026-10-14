<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/money-trail.md §2 (MT-1b):
 * - credits: one row per customer and currency (unique). Duplicates, if any, are merged first (their sum is kept on
 *   the lowest id; the total, and so the journal, does not change).
 * - money_event_notes: the reason staff give for a credit adjustment. Kept apart from the append-only journal, because
 *   free text can hold personal data: anonymisation (§9) can clear a note, never an event.
 */
return new class extends Migration
{
    /** The key keeps its first name; the column is the account's (client_id) once replayed after PR 2a. */
    private const UNIQUE = 'credits_user_id_currency_code_unique';

    private function owner(): string
    {
        return Schema::hasColumn('credits', 'client_id') ? 'client_id' : 'user_id';
    }

    public function up(): void
    {
        $owner = $this->owner();
        $duplicates = DB::table('credits')->select($owner, 'currency_code')
            ->groupBy($owner, 'currency_code')->havingRaw('count(*) > 1')->get();
        foreach ($duplicates as $duplicate) {
            $rows = DB::table('credits')->where($owner, $duplicate->{$owner})
                ->where('currency_code', $duplicate->currency_code)->orderBy('id')->get();
            $total = $rows->sum(fn (object $row) => (int) round((float) $row->amount * 100));
            DB::table('credits')->where('id', $rows->first()->id)->update(['amount' => $total / 100]);
            DB::table('credits')->whereIn('id', $rows->skip(1)->pluck('id'))->delete();
        }

        Schema::table('credits', function (Blueprint $table) use ($owner) {
            $table->unique([$owner, 'currency_code'], self::UNIQUE);
        });

        Schema::create('money_event_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('money_event_id')->unique()->constrained('money_events')->restrictOnDelete();
            $table->text('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_event_notes');
        $owner = $this->owner();
        Schema::table('credits', function (Blueprint $table) use ($owner) {
            // MariaDB/MySQL use the unique key for the owner's foreign key (it replaced the FK's own index): give the
            // foreign key an index of its own first, or the unique key cannot be dropped.
            $table->index($owner);
            $table->dropUnique(self::UNIQUE);
        });
    }
};
