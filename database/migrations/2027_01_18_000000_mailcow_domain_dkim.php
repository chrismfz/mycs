<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The DKIM record mailcow has for the domain (mailcow:sync): what its DNS must publish (the service's mail setup); and
        // whether its DNS is right, for the list (owner 2026-10-28: as a domain's status, at a glance).
        Schema::table('mailcow_domains', function (Blueprint $table) {
            $table->string('dkim_selector', 63)->nullable()->after('admins');
            $table->text('dkim_txt')->nullable()->after('dkim_selector');
            // Its DNS as last checked (the sync; MailDns): ok, or fail with the records that are not right (owner
            // 2026-10-28: a plain ✓ or ✗ in the list, any error).
            $table->string('dns_status', 8)->nullable()->after('dkim_txt');
            $table->string('dns_issues')->nullable()->after('dns_status');
            $table->timestamp('dns_checked_at')->nullable()->after('dns_status');
        });
    }

    public function down(): void
    {
        Schema::table('mailcow_domains', function (Blueprint $table) {
            $table->dropColumn(['dkim_selector', 'dkim_txt', 'dns_status', 'dns_issues', 'dns_checked_at']);
        });
    }
};
