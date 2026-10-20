<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets, PR Γ (docs/tickets.md §3 Δ, §6ε): a guest sees and answers their ticket by a link mailed to their address
 * only. Each link a random token of its own (kept hashed), 30 days, a new one with every mail; all revoked when staff
 * link the ticket to an account, change its address, or revoke them. The guest mails get the link.
 */
return new class extends Migration
{
    /** The sentence the guest mails had (en, el) and what replaces it. */
    private const TEXTS = [
        'To answer, reply to this email.' => 'To answer, reply to this email or [open your ticket]({{ ticket_url }}).',
        'To add anything, reply to this email.' => 'To add anything, reply to this email or [open your ticket]({{ ticket_url }}).',
        'Για να απαντήσετε, απαντήστε σε αυτό το email.' => 'Για να απαντήσετε, απαντήστε σε αυτό το email ή [ανοίξτε το αίτημά σας]({{ ticket_url }}).',
        'Για να προσθέσετε κάτι, απαντήστε σε αυτό το email.' => 'Για να προσθέσετε κάτι, απαντήστε σε αυτό το email ή [ανοίξτε το αίτημά σας]({{ ticket_url }}).',
    ];

    public function up(): void
    {
        Schema::create('ticket_guest_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        $this->replace(self::TEXTS);
        if (!DB::table('notification_templates')->where('key', 'ticket_link')->exists()) {
            DB::table('notification_templates')->insert(array_merge(EmailTemplateSeeder::mapping['ticket_link'], [
                'key' => 'ticket_link',
                'enabled' => true,
                'translations' => json_encode(EmailTemplateSeeder::translations['ticket_link'], JSON_UNESCAPED_UNICODE),
            ]));
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', 'ticket_link')->delete();
        $this->replace(array_flip(self::TEXTS));
        Schema::dropIfExists('ticket_guest_links');
    }

    /** @param  array<string, string>  $texts */
    private function replace(array $texts): void
    {
        foreach (DB::table('notification_templates')->whereIn('key', ['guest_ticket_message', 'ticket_received'])->get() as $template) {
            $changes = [];
            foreach (['body', 'translations'] as $column) {
                $value = (string) $template->{$column};
                $new = $column === 'translations' ? $this->inJson($value, $texts) : strtr($value, $texts);
                if ($new !== $value) {
                    $changes[$column] = $new;
                }
            }
            if ($changes !== []) {
                DB::table('notification_templates')->where('id', $template->id)->update($changes);
            }
        }
    }

    /** The texts in a translations JSON column, as its strings (escaped or not), never breaking the JSON. */
    private function inJson(string $json, array $texts): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }
        array_walk_recursive($data, function (&$value) use ($texts) {
            $value = is_string($value) ? strtr($value, $texts) : $value;
        });

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE);
    }
};
