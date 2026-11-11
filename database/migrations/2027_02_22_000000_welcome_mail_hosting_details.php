<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The welcome mail says what WHMCS's hosting and server welcomes did (owner 2026-11-11): the control panel's and
     * webmail's addresses (the module's `welcomeData`), FTP and mail hosts, the nameservers, and a machine's SSH line.
     * Added before the «Server» block, only where the mail still has that block as seeded; one staff rewrote stays theirs.
     */
    public function up(): void
    {
        $this->each(fn (string $body, string $locale) => str_contains($body, '{% if panel_url %}') || !str_contains($body, self::ANCHOR)
            ? $body : str_replace(self::ANCHOR, self::block($locale) . self::ANCHOR, $body));
    }

    public function down(): void
    {
        $this->each(fn (string $body, string $locale) => str_replace(self::block($locale), '', $body));
    }

    private const ANCHOR = "{% if service_server %}\n**Server**\n";

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

    private static function block(string $locale): string
    {
        return ($locale === 'el' ? <<<'MD'
            {% if panel_url %}
            **Πίνακας ελέγχου**
            - Πίνακας ελέγχου: <a href="{{ panel_url }}">{{ panel_url }}</a>
            {% if webmail_url %}
            - Webmail: <a href="{{ webmail_url }}">{{ webmail_url }}</a>
            {% endif %}
            {% if whm_url %}
            - WHM (reseller): <a href="{{ whm_url }}">{{ whm_url }}</a>
            {% endif %}

            Για την ασφάλειά σας, αλλάξτε τον κωδικό σας μετά την πρώτη σύνδεση.
            {% endif %}

            {% if server_hostname %}
            **FTP και email**
            {% if service.username %}
            - FTP: {{ server_hostname }}, με το όνομα χρήστη και τον κωδικό παραπάνω
            {% endif %}
            {% if service.domain %}
            - Server εισερχόμενης και εξερχόμενης αλληλογραφίας, αφού το domain σας δείξει εδώ: mail.{{ service.domain }}. Συνδεθείτε με ολόκληρη τη διεύθυνση email και ενεργοποιήστε το SMTP authentication.
            {% endif %}
            {% endif %}

            {% if nameservers %}
            **Nameservers**
            - {{ nameservers }}

            Αν το domain σας είναι καταχωρημένο αλλού, ορίστε αυτούς τους nameservers στον καταχωρητή του. Η αλλαγή μπορεί να χρειαστεί 24–48 ώρες για να ισχύσει παντού.
            {% endif %}

            {% if ssh_host %}
            **Πρόσβαση SSH**
            - Host: {{ ssh_host }}, χρήστης: root, port: 22
            - Από Linux ή macOS: `ssh root@{{ ssh_host }}`

            Για την ασφάλειά σας, αλλάξτε τον κωδικό του root μετά την πρώτη σύνδεση.
            {% endif %}

            MD : <<<'MD'
            {% if panel_url %}
            **Control panel**
            - Control panel: <a href="{{ panel_url }}">{{ panel_url }}</a>
            {% if webmail_url %}
            - Webmail: <a href="{{ webmail_url }}">{{ webmail_url }}</a>
            {% endif %}
            {% if whm_url %}
            - WHM (reseller): <a href="{{ whm_url }}">{{ whm_url }}</a>
            {% endif %}

            For your security, change your password after you first sign in.
            {% endif %}

            {% if server_hostname %}
            **FTP and email**
            {% if service.username %}
            - FTP: {{ server_hostname }}, with the username and password above
            {% endif %}
            {% if service.domain %}
            - Incoming and outgoing mail server, once your domain points here: mail.{{ service.domain }}. Sign in with the full email address and turn on SMTP authentication.
            {% endif %}
            {% endif %}

            {% if nameservers %}
            **Nameservers**
            - {{ nameservers }}

            If your domain is registered elsewhere, set these nameservers at its registrar. The change may take 24–48 hours to reach everyone.
            {% endif %}

            {% if ssh_host %}
            **SSH access**
            - Host: {{ ssh_host }}, user: root, port: 22
            - From Linux or macOS: `ssh root@{{ ssh_host }}`

            For your security, change the root password after you first sign in.
            {% endif %}

            MD) . "\n";
    }
};
