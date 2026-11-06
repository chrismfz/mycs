<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The «Server» block's label per language: [your IPs]. */
    private const LABELS = ['en' => 'Your IPs', 'el' => 'Οι IP σας'];

    /**
     * The welcome mail says where the service lives, as WHMCS's did: the hosting panel's hostname and IP, the service's
     * own IPs (owner 2026-11-06). Added before the product's snippet, only where the mail still has it as seeded; one
     * staff rewrote without it stays theirs.
     */
    public function up(): void
    {
        $this->each(fn (string $body, string $locale) => str_contains($body, '{% if service_server %}') || !str_contains($body, self::anchor())
            ? $body : str_replace(self::anchor(), self::block($locale) . self::anchor(), $body));
    }

    public function down(): void
    {
        $this->each(fn (string $body, string $locale) => str_replace(self::block($locale), '', $body));
    }

    private function each(Closure $change): void
    {
        $row = DB::table('notification_templates')->where('key', 'new_server_created')->first();
        if ($row === null) {
            return; // a new database: the seeder writes the new one
        }
        $translations = is_string($row->translations) ? json_decode($row->translations, true) : null;
        if (is_array($translations) && is_string($translations['el']['body'] ?? null)) {
            $translations['el']['body'] = $change($translations['el']['body'], 'el');
        }
        DB::table('notification_templates')->where('id', $row->id)->update([
            'body' => $change((string) $row->body, 'en'),
            'translations' => is_array($translations) ? json_encode($translations, JSON_UNESCAPED_UNICODE) : $row->translations,
        ]);
    }

    private static function anchor(): string
    {
        return "{% if product_info %}\n";
    }

    private static function block(string $locale): string
    {
        return "{% if service_server %}\n**Server**\n{% if server_hostname %}\n- Hostname: {{ server_hostname }}\n{% endif %}\n"
            . "{% if server_ip %}\n- IP: {{ server_ip }}\n{% endif %}\n{% if service_ips %}\n- " . self::LABELS[$locale] . ": {{ service_ips }}\n{% endif %}\n{% endif %}\n\n";
    }
};
