<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets, PR A3 (docs/tickets.md §3 Α, §6γ): every mail we send about a ticket has a Message-ID of ours, kept here
 * before it is sent (the same on every retry); these are the only thread keys. Staff told of new tickets and replies
 * per department. The mails to a guest and the «we received it» one, and the reply's author by its name (it was the
 * customer's login, blank for staff).
 */
return new class extends Migration
{
    private const KEYS = ['guest_ticket_message', 'ticket_received'];

    public function up(): void
    {
        Schema::create('ticket_mail_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('message_id')->unique();
            $table->string('kind', 16); // reply, received
            $table->string('recipient')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('ticket_department_staff', function (Blueprint $table) {
            $table->foreignId('ticket_department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->primary(['ticket_department_id', 'staff_id']);
        });

        foreach (self::KEYS as $key) {
            if (!DB::table('notification_templates')->where('key', $key)->exists()) {
                DB::table('notification_templates')->insert(array_merge(EmailTemplateSeeder::mapping[$key], [
                    'key' => $key,
                    'enabled' => true,
                    'translations' => json_encode(EmailTemplateSeeder::translations[$key], JSON_UNESCAPED_UNICODE),
                ]));
            }
        }
        $this->replace('{{ ticketMessage.user.name }}', '{{ author_name }}');
    }

    public function down(): void
    {
        $this->replace('{{ author_name }}', '{{ ticketMessage.user.name }}');
        DB::table('notification_templates')->whereIn('key', self::KEYS)->delete();
        Schema::dropIfExists('ticket_department_staff');
        Schema::dropIfExists('ticket_mail_ids');
    }

    private function replace(string $from, string $to): void
    {
        foreach (DB::table('notification_templates')->where('key', 'new_ticket_message')->get() as $template) {
            $changes = [];
            foreach (['subject', 'body', 'in_app_title', 'in_app_body', 'translations'] as $column) {
                if (is_string($template->{$column}) && str_contains($template->{$column}, $from)) {
                    $changes[$column] = str_replace($from, $to, $template->{$column});
                }
            }
            if ($changes !== []) {
                DB::table('notification_templates')->where('id', $template->id)->update($changes);
            }
        }
    }
};
