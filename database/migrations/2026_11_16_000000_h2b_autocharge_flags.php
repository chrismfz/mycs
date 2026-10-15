<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR H2b (docs/payments.md «Αυτόματη χρέωση»): an invoice staff keep from being charged automatically, the month a saved
 * method's expiry was last told to its customer (WHMCS CCDaySendExpiryNotices), and that notice's template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Staff: not charged to the saved method by the cron (WHMCS «Do not apply auto-charge»); paid by hand.
            $table->boolean('no_auto_charge')->default(false);
        });
        Schema::table('billing_agreements', function (Blueprint $table) {
            $table->timestamp('expiry_notified_at')->nullable()->after('expiry');
        });

        $key = 'card_expiring';
        DB::table('notification_templates')->insertOrIgnore(array_merge(EmailTemplateSeeder::mapping[$key], [
            'key' => $key,
            'enabled' => true,
            'translations' => json_encode(EmailTemplateSeeder::translations[$key], JSON_UNESCAPED_UNICODE),
        ]));
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', 'card_expiring')->delete();
        Schema::table('billing_agreements', fn (Blueprint $table) => $table->dropColumn('expiry_notified_at'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('no_auto_charge'));
    }
};
