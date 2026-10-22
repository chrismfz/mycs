<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SSL step 2: a renewal is marked before it is asked for (`renew_requested_at`, the order end it renews `renew_for`),
 * so a lost answer never renews twice; a renewal that makes a new order keeps the old ids; the certificate the customer
 * was told of (`notified_hash`), so a new one (issued, reissued) is told once. With its mail, en and el.
 */
return new class extends Migration
{
    private const KEY = 'ssl_certificate_issued';

    public function up(): void
    {
        Schema::table('ssl_orders', function (Blueprint $table) {
            $table->timestamp('renew_requested_at')->nullable();
            $table->timestamp('renew_for')->nullable();
            $table->json('previous_order_ids')->nullable();
            $table->string('notified_hash', 64)->nullable();
        });
        if (!DB::table('notification_templates')->where('key', self::KEY)->exists()) {
            DB::table('notification_templates')->insert(array_merge(EmailTemplateSeeder::mapping[self::KEY], [
                'key' => self::KEY,
                'enabled' => true,
                'translations' => json_encode(EmailTemplateSeeder::translations[self::KEY], JSON_UNESCAPED_UNICODE),
            ]));
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', self::KEY)->delete();
        Schema::table('ssl_orders', function (Blueprint $table) {
            $table->dropColumn(['renew_requested_at', 'renew_for', 'previous_order_ids', 'notified_hash']);
        });
    }
};
