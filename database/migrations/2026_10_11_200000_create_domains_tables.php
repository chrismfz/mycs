<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains (ported from Ekdosi, docs/domains-spike.md): one instance per company, so no
 * company_id; customer -> users, ServiceContract -> services. Registrar credentials live
 * encrypted in domain_registrar_connections.config, never in .env or the repo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_registrar_connections', function (Blueprint $table) {
            $table->id();
            $table->string('registrar', 40);
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('mode', 20)->default('off'); // off | sandbox | production
            $table->text('config')->nullable();          // encrypted:array
            $table->timestamps();
            $table->softDeletes();
            $table->index(['registrar', 'is_active']);
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('legacy_id')->nullable()->unique(); // WHMCS tbldomains.id
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registrar_connection_id')->nullable()->constrained('domain_registrar_connections')->nullOnDelete();
            $table->string('sld', 190);
            $table->string('tld', 30);
            $table->string('fqdn', 190)->unique();
            $table->string('status', 30)->default('active');
            $table->date('registered_at')->nullable();
            $table->date('transferred_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->boolean('transfer_lock')->default(false);
            $table->boolean('whois_privacy')->default(false);
            $table->boolean('dnssec_enabled')->default(false);
            $table->boolean('consent_publish')->default(false);
            $table->string('registrar_domain_id', 60)->nullable();
            $table->string('idn_script', 30)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->unsignedSmallInteger('grace_days_override')->nullable();
            $table->unsignedSmallInteger('redemption_days_override')->nullable();
            $table->decimal('fee_override', 14, 2)->nullable();
            $table->decimal('price_override', 14, 2)->nullable();
            $table->json('module_meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
            $table->index('expires_at');
        });

        Schema::create('domain_nameservers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('host', 190);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('domain_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // registrant | admin | tech | billing
            $table->string('name', 190);
            $table->string('org', 190)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address1', 190)->nullable();
            $table->string('address2', 190)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('registrar_contact_handle', 40)->nullable();
            $table->timestamps();
            $table->unique(['domain_id', 'type']);
        });

        Schema::create('domain_registrar_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registrar_connection_id')->nullable()->constrained('domain_registrar_connections')->nullOnDelete();
            $table->string('action', 40);
            $table->string('status', 20);
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['action', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_registrar_logs');
        Schema::dropIfExists('domain_contacts');
        Schema::dropIfExists('domain_nameservers');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('domain_registrar_connections');
    }
};
