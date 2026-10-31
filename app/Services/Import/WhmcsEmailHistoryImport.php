<?php

namespace App\Services\Import;

use App\Models\Setting;
use App\Support\Redact;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * What WHMCS mailed its customers (`tblemails`), the last twelve months, as the mail log: so a customer's «My emails» page
 * still shows what they were sent (owner, 2026-10-27: a year, not all 13,000). The log's retention (`cronjob_delete_email_logs`)
 * is raised to 365 days where it is shorter, else its first prune deletes nine of the twelve months; a longer one, or «0»
 * (for ever), stays.
 *
 * - Only mail that was sent. A failed one is not brought: with a template key it would be a candidate of `app:mail-resend-failed`,
 *   and mail that never reached anyone is not history. Nothing pending either. The rows carry the key `whmcs_history` (the
 *   customer's page lists the rows that have one) and `message_id` = `whmcs:<id>`, so a second run adds nothing.
 * - A body is scrubbed the way this app's own log masks secrets: the passwords a welcome email carried (in any wording and
 *   spacing), the keys in a link's query or path (a password reset's), and every known secret (`Redact::text`). A body that
 *   cannot be scrubbed is not kept. Cut at 200 KB; the recipient at 255 characters. Rows are written directly (an import:
 *   no audit of each).
 */
final class WhmcsEmailHistoryImport
{
    public const TEMPLATE_KEY = 'whmcs_history';

    private const BODY_MAX = 200_000;

    private const CHUNK = 500;

    private const NOT_KEPT = '[the body of this mail is not kept: it could not be cleaned of secrets]';

    /** The ways a space is written in an HTML mail. */
    private const SPACES = '/&nbsp;|&#0*160;|&#x0*a0;|\x{00A0}/iu';

    /** A label that introduces a secret («Password», «Κωδικός πρόσβασης»…). */
    private const LABEL = '(?:pass(?:word|wd|phrase)?|κωδικ\p{L}*)';

    /** @var array<string, int> */
    private array $counts = ['mails' => 0, 'older' => 0, 'pending' => 0, 'failed' => 0, 'again' => 0, 'scrubbed' => 0];

    public function __construct(private readonly PDO $whmcs, private readonly ?string $since = null) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        $since = $this->since ?? now()->subYear()->toDateTimeString();
        try {
            $statement = $this->whmcs->prepare('SELECT COUNT(*) FROM tblemails WHERE date < ?');
            $statement->execute([$since]);
            $this->counts['older'] = (int) $statement->fetchColumn();
            $statement = $this->whmcs->prepare('SELECT id, userid, subject, `to`, message, date, failed, pending FROM tblemails WHERE date >= ? ORDER BY id');
            $statement->execute([$since]);
        } catch (PDOException) {
            return ['counts' => $this->counts, 'notes' => []];
        }
        $clients = DB::table('clients')->pluck('id')->flip()->all();
        $had = DB::table('email_logs')->where('template_key', self::TEMPLATE_KEY)->pluck('message_id')->flip()->all();
        $batch = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if ((int) $row['pending'] === 1) {
                $this->counts['pending']++;
            } elseif ((int) $row['failed'] === 1) {
                $this->counts['failed']++;
            } elseif (isset($had["whmcs:{$row['id']}"])) {
                $this->counts['again']++;
            } else {
                $date = (string) $row['date'];
                $batch[] = [
                    'client_id' => isset($clients[(int) $row['userid']]) ? (int) $row['userid'] : null,
                    'subject' => mb_substr(mb_scrub(trim((string) $row['subject'])), 0, 255),
                    'to' => mb_substr(mb_scrub(trim((string) $row['to'])), 0, 255),
                    'body' => $this->scrub((string) $row['message']),
                    'status' => 'sent',
                    'sent_at' => $date,
                    'attempts' => 1,
                    'trigger' => 'auto',
                    'template_key' => self::TEMPLATE_KEY,
                    'message_id' => "whmcs:{$row['id']}",
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
                $this->counts['mails']++;
            }
            if (count($batch) >= self::CHUNK) {
                DB::table('email_logs')->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('email_logs')->insert($batch);
        }
        if ($this->counts['mails'] > 0) {
            $this->raiseRetention();
        }

        return ['counts' => $this->counts, 'notes' => []];
    }

    /** 365 days where the log is kept for fewer; a longer retention, or none (0), is the owner's. */
    private function raiseRetention(): void
    {
        $key = ['key' => 'cronjob_delete_email_logs', 'settingable_type' => null, 'settingable_id' => null];
        $current = Setting::query()->where($key)->value('value');
        $days = $current === null || trim((string) $current) === '' ? 90 : (int) $current;
        if ($days !== 0 && $days < 365) {
            Setting::query()->updateOrCreate($key, ['value' => '365', 'type' => 'string']);
        }
    }

    /** The log's own masking, and what only a WHMCS body carries. Never the body as it came when it cannot be cleaned. */
    private function scrub(string $body): string
    {
        $out = mb_scrub($body); // a legacy byte would make every pattern below fail
        $out = preg_replace(self::SPACES, ' ', $out);
        // A link: the query's secret keys (also after «&amp;») and a token in its path (a reset's: …/redeem/<token>, rp=%2F…).
        $out = is_string($out) ? preg_replace_callback('~https?://[^\s"\'<>]+~i', function (array $m) {
            $url = str_replace('&amp;', '&', $m[0]);
            $url = Redact::url($url);
            $url = (string) preg_replace('#((?:/|%2F)(?:redeem|verify|confirm|token|reset)(?:/|%2F))[A-Za-z0-9_\-.~%]{8,}#i', '$1' . Redact::MASK, $url);

            return str_contains($m[0], '&amp;') ? str_replace('&', '&amp;', $url) : $url;
        }, $out) : null;
        $tags = '(?:<[^>]*>|\s)*';
        $mask = Redact::MASK;
        // «Password: X», «Κωδικός πρόσβασης: X», «Your new password is: X» (a few words between the label and the colon)…
        $out = is_string($out) ? preg_replace('~(?<!\p{L})(' . self::LABEL . ')(?!\p{L})([^\n:=<]{0,40}?' . $tags . '[:=]' . $tags . ')[^\s<][^\s<]*~iu', '$1$2' . $mask, $out) : null;
        // …and «password is X» with no colon.
        $out = is_string($out) ? preg_replace('~(?<!\p{L})(' . self::LABEL . '\s+(?:is|είναι)\s*' . $tags . ')[^\s<:][^\s<]*~iu', '$1' . $mask, $out) : null;
        $out = is_string($out) ? Redact::text($out) : null;
        if (!is_string($out)) {
            return self::NOT_KEPT;
        }
        if (str_contains($out, Redact::MASK) && !str_contains($body, Redact::MASK)) {
            $this->counts['scrubbed']++; // something was masked (not only a space made plain)
        }

        return mb_strcut($out, 0, self::BODY_MAX);
    }
}
