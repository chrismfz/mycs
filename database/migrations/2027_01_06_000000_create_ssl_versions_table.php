<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SSL vault (docs/ssl-vault.md): every key and CSR an SSL order has used, and the certificate and chain issued for it.
 * A key MyCS made (or staff attached) is kept encrypted (APP_KEY, owner 2026-10-24); a CSR the customer pasted has
 * none. A row is matched to its certificate by the public key (`spki_sha256`). Old rows stay: a reissue adds one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssl_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ssl_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('source', 16); // generated, pasted (the customer's own CSR), synced (a certificate found)
            $table->string('key_type', 16)->nullable();
            $table->string('key_description', 32)->nullable();
            $table->text('private_key')->nullable();
            $table->char('spki_sha256', 64)->nullable()->index();
            $table->text('csr')->nullable();
            $table->string('common_name')->nullable();
            $table->json('host_names')->nullable();
            $table->text('certificate')->nullable();
            $table->text('chain')->nullable();
            $table->string('serial', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->nullableMorphs('creator');
            $table->timestamps();
            $table->unique(['ssl_order_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssl_versions');
    }
};
