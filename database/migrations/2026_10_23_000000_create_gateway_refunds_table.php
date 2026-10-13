<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR 2c-2 (docs/payments.md): every refund a gateway made, by its own id there, once. A refund MyCS asked for is written
 * when the gateway answers; one made in the gateway's dashboard arrives by webhook and is recorded on the payment then.
 * The same refund told twice (MyCS's own, echoed by the webhook; a retried webhook) is recorded once. The customer is
 * told of a refund (notification template invoice_refunded, WHMCS's «Invoice Refund Confirmation»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_transaction_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 50);
            $table->string('reference', 191);
            $table->decimal('amount', 17, 2);
            $table->string('currency_code', 3);
            // What was recorded on the payment: less than amount when the gateway gave back more than was left on it.
            $table->decimal('recorded_amount', 17, 2);
            $table->string('origin', 20); // mycs: asked for here; gateway: made in the gateway's dashboard
            $table->timestamps();

            $table->unique(['gateway', 'reference']);
        });

        DB::table('notification_templates')->insertOrIgnore(
            array_merge(EmailTemplateSeeder::mapping['invoice_refunded'], ['key' => 'invoice_refunded', 'enabled' => true])
        );
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', 'invoice_refunded')->delete();
        Schema::dropIfExists('gateway_refunds');
    }
};
