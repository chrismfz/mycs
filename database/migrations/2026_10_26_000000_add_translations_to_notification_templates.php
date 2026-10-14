<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A template's text in other languages (audit sweep Σ4): {"el": {"subject", "body", "in_app_title", "in_app_body"}}.
 * The columns stay the default (English) text; a mail goes out in the recipient's language when the template has it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->json('translations')->nullable()->after('in_app_url');
        });
    }

    public function down(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->dropColumn('translations');
        });
    }
};
