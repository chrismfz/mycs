<?php

namespace App\Services\Import;

use App\Models\Setting;
use App\Support\Redact;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * What WHMCS mailed its customers (`tblemails`), the last twelve months, as the mail log: so a customer's page still shows what
 * they were sent (owner, 2026-10-27: a year, not all 13,000). The log's retention (`cronjob_delete_email_logs`) is raised to
 * 365 days, else its first prune deletes nine of the twelve months.
 *
 * - A body is scrubbed the way this app's own log masks secrets: the passwords a welcome email carried (`Password: …`), the
 *   keys in a link's query (a password reset's), and every known secret (`Redact::text`). Nothing pending is brought.
 * - A body is cut at 200 KB; the recipient at 255 characters. Rows are written directly (an import: no audit of each).
 */
final class WhmcsEmailHistoryImport
{
    private const BODY_MAX = 200_000;

    private const CHUNK = 500;

    /** @var array<string, int> */
    private array $counts = ['mails' => 0, 'older' => 0, 'pending' => 0, 'scrubbed' => 0];

    public function __construct(private readonly PDO $whmcs, private readonly ?string $since = null) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        $since = $this->since ?? now()->subYear()->toDateTimeString();
        try {
            $this->counts['older'] = (int) $this->whmcs->query($this->count("date < '{$since}'"))->fetchColumn();
            $statement = $this->whmcs->prepare('SELECT id, userid, subject, `to`, message, date, failed, failure_reason, pending FROM tblemails WHERE date >= ? ORDER BY id');
            $statement->execute([$since]);
        } catch (PDOException) {
            return ['counts' => $this->counts, 'notes' => []];
        }
        $clients = DB::table('clients')->pluck('id')->flip()->all();
        $batch = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if ((int) $row['pending'] === 1) {
                $this->counts['pending']++;

                continue;
            }
            $date = (string) $row['date'];
            $failed = (int) $row['failed'] === 1;
            $batch[] = [
                'client_id' => isset($clients[(int) $row['userid']]) ? (int) $row['userid'] : null,
                'subject' => mb_substr(trim((string) $row['subject']), 0, 255),
                'to' => mb_substr(trim((string) $row['to']), 0, 255),
                'body' => mb_strcut($this->scrub((string) $row['message']), 0, self::BODY_MAX),
                'status' => $failed ? 'failed' : 'sent',
                'error' => $failed ? mb_substr((string) $row['failure_reason'], 0, 250) : null,
                'sent_at' => $failed ? null : $date,
                'failed_at' => $failed ? $date : null,
                'attempts' => 1,
                'trigger' => 'auto',
                'created_at' => $date,
                'updated_at' => $date,
            ];
            $this->counts['mails']++;
            if (count($batch) >= self::CHUNK) {
                DB::table('email_logs')->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('email_logs')->insert($batch);
        }
        if ($this->counts['mails'] > 0) {
            Setting::query()->updateOrCreate(['key' => 'cronjob_delete_email_logs', 'settingable_type' => null, 'settingable_id' => null], ['value' => '365', 'type' => 'string']);
        }

        return ['counts' => $this->counts, 'notes' => []];
    }

    /** The log's own masking, and what only a WHMCS body carries. */
    private function scrub(string $body): string
    {
        $out = (string) preg_replace_callback('~https?://[^\s"\'<>]+~i', fn (array $m) => Redact::url($m[0]), $body);
        $out = (string) preg_replace('~((?:pass(?:word|wd)?|κωδικ\p{L}*)\s*(?:</[a-z]+>\s*)*[:=：]\s*(?:<[^>]+>\s*)*)([^\s<&][^\s<]*)~iu', '$1' . Redact::MASK, $out);
        $out = Redact::text($out);
        if ($out !== $body) {
            $this->counts['scrubbed']++;
        }

        return $out;
    }

    private function count(string $where): string
    {
        return "SELECT COUNT(*) FROM tblemails WHERE {$where}";
    }
}
