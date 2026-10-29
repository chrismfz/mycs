<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The welcome mail's login block as seeded, per language: [label, username, password]. */
    private const LABELS = [
        'en' => ['Login details', 'Username', 'Password'],
        'el' => ['Στοιχεία σύνδεσης', 'Όνομα χρήστη', 'Κωδικός'],
    ];

    /**
     * The welcome mail shows a password without a username too (a VPS's root password, a product with no module): it
     * went out with no login at all (ADV-533-1). Only where the block is still as seeded; one staff edited stays theirs.
     */
    public function up(): void
    {
        $this->swap(fn (string $old, string $new) => [$old, $new]);
    }

    public function down(): void
    {
        $this->swap(fn (string $old, string $new) => [$new, $old]);
    }

    private function swap(Closure $direction): void
    {
        $row = DB::table('notification_templates')->where('key', 'new_server_created')->first();
        if ($row === null) {
            return; // a new database: the seeder writes the new one
        }
        [$from, $to] = $direction(...$this->blocks('en'));
        $body = str_replace($from, $to, (string) $row->body);
        $translations = is_string($row->translations) ? json_decode($row->translations, true) : null;
        if (is_array($translations) && is_string($translations['el']['body'] ?? null)) {
            [$from, $to] = $direction(...$this->blocks('el'));
            $translations['el']['body'] = str_replace($from, $to, $translations['el']['body']);
        }
        DB::table('notification_templates')->where('id', $row->id)->update([
            'body' => $body,
            'translations' => is_array($translations) ? json_encode($translations, JSON_UNESCAPED_UNICODE) : $row->translations,
        ]);
    }

    /** @return array{string, string} the block as seeded before, and now */
    private function blocks(string $locale): array
    {
        [$head, $user, $password] = self::LABELS[$locale];
        $login = "{% if service.username %}\n**{$head}**\n- {$user}: {{ service.username }}\n{% if service_password %}\n- {$password}: {{ service_password }}\n{% endif %}\n";

        return [
            $login . "{% endif %}\n",
            $login . "{% else %}\n{% if service_password %}\n**{$head}**\n- {$password}: {{ service_password }}\n{% endif %}\n{% endif %}\n",
        ];
    }
};
