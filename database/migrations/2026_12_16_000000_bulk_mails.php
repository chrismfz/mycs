<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The mass mail (ROADMAP 6δ, WHMCS's Mass Mail Tool): a message to the accounts a filter finds, each once (its recipient
 * row), off the request. Its two kinds are notification templates (their layout, and the customer's preference for
 * news): «announcement», always sent; «announcement_news», which a customer turns off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_mails', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind', 16); // important | news
            $table->string('subject');
            $table->text('body');
            $table->string('subject_el')->nullable();
            $table->text('body_el')->nullable();
            $table->json('filters')->nullable();
            $table->string('status', 16)->default('draft'); // draft | sending | sent
            $table->unsignedInteger('recipients_count')->default(0);
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('bulk_mail_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_mail_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending'); // pending | sending | sent | skipped | failed
            $table->string('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['bulk_mail_id', 'client_id']);
            $table->index(['bulk_mail_id', 'status']);
        });

        foreach (['announcement', 'announcement_news'] as $key) {
            if (!DB::table('notification_templates')->where('key', $key)->exists()) {
                DB::table('notification_templates')->insert(array_merge(EmailTemplateSeeder::mapping[$key], [
                    'key' => $key,
                    'enabled' => true,
                    'translations' => isset(EmailTemplateSeeder::translations[$key]) ? json_encode(EmailTemplateSeeder::translations[$key], JSON_UNESCAPED_UNICODE) : null,
                ]));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_mail_recipients');
        Schema::dropIfExists('bulk_mails');
        DB::table('notification_templates')->whereIn('key', ['announcement', 'announcement_news'])->delete();
    }
};
