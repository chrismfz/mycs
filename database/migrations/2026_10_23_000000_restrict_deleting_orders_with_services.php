<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order with services is not deleted (audit sweep DATA-2, SVC-7): the cascade wiped its services in the database,
 * with no events, no terminate on the server, and invoice lines left pointing at nothing. Staff delete the services
 * first (DeleteService), then the order.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuild('restrictOnDelete');
    }

    public function down(): void
    {
        $this->rebuild('cascadeOnDelete');
    }

    private function rebuild(string $onDelete): void
    {
        foreach (Schema::getForeignKeys('services') as $key) {
            if ($key['columns'] === ['order_id']) {
                Schema::table('services', fn (Blueprint $table) => $table->dropForeign($key['name'] ?: ['order_id']));
            }
        }
        Schema::table('services', fn (Blueprint $table) => $table->foreign('order_id')->references('id')->on('orders')->{$onDelete}());
    }
};
