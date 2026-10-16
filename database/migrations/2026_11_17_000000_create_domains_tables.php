<?php

use App\Enums\DomainStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains (docs/domains.md, D1): Ekdosi's model without company_id. A domain is its own record, billed directly (D-4),
 * with its contact roles on the account's contacts (D-3); a registrar connection keeps its credentials as encrypted
 * Settings (D-21). Nothing here is deleted while it has history (D-18): domains end by status, connections and TLDs
 * in use are refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_registrar_connections', function (Blueprint $table) {
            $table->id();
            $table->string('registrar', 40); // the driver's key (openprovider, manual, …)
            $table->string('label');
            $table->boolean('is_active')->default(false);
            $table->string('mode', 20)->default('off'); // off | sandbox | production
            $table->timestamps();
            $table->index(['registrar', 'is_active']);
        });

        Schema::create('domain_tlds', function (Blueprint $table) {
            $table->id();
            $table->string('tld', 63)->unique(); // without the dot: com, gr, com.gr
            $table->foreignId('registrar_connection_id')->nullable()->constrained('domain_registrar_connections')->restrictOnDelete();
            $table->boolean('epp_code_required')->default(true);
            $table->boolean('dns_management')->default(false);
            $table->boolean('email_forwarding')->default(false);
            $table->boolean('id_protection')->default(false);
            $table->boolean('allow_idn')->default(false);
            // Null: the registrar's default, or none (WHMCS -1). Their fees are prices (operation grace / redemption).
            $table->unsignedSmallInteger('grace_days')->nullable();
            $table->unsignedSmallInteger('redemption_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Per TLD, operation, years and currency of MyCS (D-5, D-6, D-8): price null = not offered, 0 = free and offered;
        // the years offered are the rows with a price. The grace and redemption fees are years 1 of their operation.
        Schema::create('domain_tld_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_tld_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 20); // register | renew | transfer | grace | redemption
            $table->unsignedTinyInteger('years');
            $table->string('currency_code', 3);
            // A currency deleted takes its TLD prices, as products' (a currency domains bill in is refused first).
            $table->foreign('currency_code')->references('code')->on('currencies')->cascadeOnDelete();
            $table->decimal('price', 17, 2)->nullable();
            // The registrar's cost, in its own currency (the pricing sync writes only these).
            $table->decimal('cost', 17, 2)->nullable();
            $table->string('cost_currency', 3)->nullable();
            $table->timestamps();
            $table->unique(['domain_tld_id', 'operation', 'years', 'currency_code'], 'domain_tld_prices_unique');
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id(); // = WHMCS tbldomains.id when imported (D-11)
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('domain_tld_id')->nullable()->constrained()->restrictOnDelete();
            // Its own connection wins over its TLD's (CNIC domains say «manual» here, D-10).
            $table->foreignId('registrar_connection_id')->nullable()->constrained('domain_registrar_connections')->restrictOnDelete();
            $table->string('sld', 190);
            $table->string('tld', 63);
            // Lowercase ASCII (IDN as punycode, no trailing dot): Domain normalises it.
            $table->string('fqdn', 255)->index();
            $table->string('status', 30);
            // An ended domain stays (D-18) and its name may come back: one *live* row per name. Computed by the database
            // from the status, so no write can skip it (an importer, a query-builder update: reviews B, A2).
            $ended = "'" . implode("','", DomainStatus::terminalValues()) . "'";
            $table->string('live_fqdn', 255)->nullable()->storedAs("CASE WHEN status IN ({$ended}) THEN NULL ELSE fqdn END")->unique();
            $table->date('registered_at')->nullable();
            $table->date('transferred_at')->nullable();
            $table->date('expires_at')->nullable(); // the registrar's: the due date too (D-4)
            // The next period: its years and price (null: the TLD's renew price), in the account's currency.
            $table->unsignedTinyInteger('years')->default(1);
            $table->string('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->decimal('recurring_amount', 17, 2)->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->boolean('transfer_lock')->default(false);
            $table->boolean('id_protection')->default(false);
            $table->boolean('dns_management')->default(false);
            $table->boolean('email_forwarding')->default(false);
            $table->boolean('dnssec_enabled')->default(false);
            // Contact roles on the account's contacts (D-3), and each one's handle at the registrar.
            foreach (['registrant', 'admin', 'tech', 'billing'] as $role) {
                $table->foreignId("{$role}_contact_id")->nullable()->constrained('contacts')->restrictOnDelete();
                $table->string("{$role}_handle", 60)->nullable();
            }
            $table->string('registrar_domain_id', 60)->nullable();
            $table->string('idn_script', 30)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->unsignedSmallInteger('grace_days_override')->nullable();
            $table->decimal('grace_fee_override', 17, 2)->nullable();
            $table->unsignedSmallInteger('redemption_days_override')->nullable();
            $table->decimal('redemption_fee_override', 17, 2)->nullable();
            $table->text('notes')->nullable(); // staff only
            $table->json('meta')->nullable(); // the registrar's raw status and the like
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index('expires_at');
        });

        Schema::create('domain_nameservers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('host', 255);
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
        });

        // Every call to a registrar (D-17): what was asked and answered, and the invoice it was for (once per invoice).
        Schema::create('domain_registrar_logs', function (Blueprint $table) {
            $table->id();
            // History (D-18): a domain or connection with a log is not deleted.
            $table->foreignId('domain_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('registrar_connection_id')->nullable()->constrained('domain_registrar_connections')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('status', 20); // ok | adopted | failed
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['domain_id', 'action', 'status']);
        });

        // Which expiry notice went out for which expiry (D-14), so none goes twice; negative days are after expiry.
        Schema::create('domain_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('days');
            $table->date('expires_on');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['domain_id', 'days', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_reminders');
        Schema::dropIfExists('domain_registrar_logs');
        Schema::dropIfExists('domain_nameservers');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('domain_tld_prices');
        Schema::dropIfExists('domain_tlds');
        Schema::dropIfExists('domain_registrar_connections');
    }
};
