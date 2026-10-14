<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment reminders (audit sweep Σ4, WHMCS's «Invoice Unpaid Reminder» and overdue notices): one row per reminder sent
 * for an invoice, so each goes once whatever runs the cron misses or repeats. Kind «due» (before the due date) or
 * «overdue» with its day. And the templates they use (invoice_payment_reminder, card_payment_due, invoice_overdue,
 * invoice_modified).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedSmallInteger('days')->default(0);
            $table->timestamp('sent_at');
            $table->unique(['invoice_id', 'kind', 'days']);
        });

        foreach (['invoice_payment_reminder', 'card_payment_due', 'invoice_overdue', 'invoice_modified'] as $key) {
            if (!DB::table('notification_templates')->where('key', $key)->exists()) {
                DB::table('notification_templates')->insert(array_merge(EmailTemplateSeeder::mapping[$key], [
                    'key' => $key,
                    'enabled' => true,
                    'translations' => json_encode(EmailTemplateSeeder::translations[$key], JSON_UNESCAPED_UNICODE),
                ]));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reminders');
        DB::table('notification_templates')->whereIn('key', ['invoice_payment_reminder', 'card_payment_due', 'invoice_overdue', 'invoice_modified'])->delete();
    }
};
