<?php

namespace App\Services\Import;

use App\Models\Service;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The services' passwords (tblhosting.password): encrypted by WHMCS with its cc_encryption_hash, which is not in its
 * database, so read through WHMCS's own API (DecryptPassword), never by reading its key, as the saved payment methods
 * (WhmcsPayMethodImport). Stored as MyCS keeps them (Service's `encrypted` cast, under this instance's APP_KEY).
 *
 * - Only the services still live here (active, suspended, pending: nixpal 356, myip 715): an ended one's password is
 *   asked of no one.
 * - Without the API nothing is asked, and it says so. One that fails is skipped (its id listed), never the run.
 * - A password is never printed, logged nor kept anywhere else: the notes carry service ids only.
 */
final class WhmcsServicePasswordImport
{
    /** Live here: the statuses a password is still used by (the panel's login, the welcome email). */
    private const LIVE = [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, Service::STATUS_PENDING];

    /** @var array<string, int> */
    private array $counts = ['services' => 0, 'imported' => 0, 'skipped' => 0];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly PDO $whmcs, private readonly ?WhmcsApi $api) {}

    /** WHMCS's answers that hold for every call (its credentials, role, allowed IPs): the step stops at the first (ADV-547-3). */
    private const REFUSED = '/permission|not allowed|authenticat|invalid ip|ip address|access denied|credentials|identifier|secret/i';

    /** Failures in a row (a WHMCS that hangs, a black-holed host) after which the rest is not asked (code review M2). */
    private const IN_A_ROW = 5;

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        $live = DB::table('services')->whereIn('status', self::LIVE)->whereNull('password')->pluck('id')->flip()->all();
        try {
            $all = $this->whmcs->query('SELECT id, password FROM tblhosting')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            $this->notes[] = 'Service passwords: this WHMCS has no tblhosting.password: none imported.'; // never the whole run (L1)

            return ['counts' => $this->counts, 'notes' => $this->notes];
        }
        $rows = array_values(array_filter($all, fn (array $row) => isset($live[(int) $row['id']]) && trim((string) ($row['password'] ?? '')) !== ''));
        $this->counts['services'] = count($rows);
        if ($rows === []) {
            return ['counts' => $this->counts, 'notes' => $this->notes];
        }
        if ($this->api === null) {
            $this->counts['skipped'] = count($rows);
            $this->notes[] = count($rows) . " live service password(s) not imported: WHMCS's API is needed to decrypt them "
                . '(--whmcs-api-url, WHMCS_API_IDENTIFIER, WHMCS_API_SECRET in the environment).';

            return ['counts' => $this->counts, 'notes' => $this->notes];
        }
        $failed = [];
        $inARow = 0;
        foreach ($rows as $index => $row) {
            try {
                $answer = $this->api->call('DecryptPassword', ['password2' => (string) $row['password']]);
                $inARow = 0;
                $password = $answer['password'] ?? null;
                if ($password === '') {
                    continue; // none set in WHMCS (an empty one encrypted): nothing to import, nothing failed (ADV-547-2)
                }
                if (!is_string($password)) {
                    throw new RuntimeException('no password in the answer');
                }
                // WHMCS keeps what staff typed HTML-escaped and gives its modules the text decoded (ADV-547-1, as #546's notes).
                $password = html_entity_decode($password, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0")) {
                    throw new RuntimeException('not a password (another key\'s ciphertext?)'); // never garbage stored (L2)
                }
                DB::table('services')->where('id', (int) $row['id'])->whereNull('password')
                    ->update(['password' => Crypt::encryptString($password)]);
                $this->counts['imported']++;
            } catch (Throwable $e) {
                // WHMCS refusing the credentials, their role or this server: the same for every call, said once with WHMCS's
                // own words (they carry no password), and nothing more asked.
                if (preg_match(self::REFUSED, $e->getMessage()) === 1) {
                    $this->counts['skipped'] += count($rows) - $index;
                    $this->notes[] = "Service passwords not imported: WHMCS refused the API's call ({$this->reason($e)}). Check the API credentials, "
                        . 'their role (DecryptPassword) and WHMCS\'s API IP access, then run the import again.';

                    return ['counts' => $this->counts, 'notes' => $this->notes];
                }
                // Its class only: an answer's message could carry what was asked.
                report(new RuntimeException("Service #{$row['id']}'s password not imported: " . class_basename($e)));
                $failed[] = (int) $row['id'];
                $this->counts['skipped']++;
                if (++$inARow >= self::IN_A_ROW) {
                    $left = array_column(array_slice($rows, $index + 1), 'id');
                    $this->counts['skipped'] += count($left);
                    $this->notes[] = self::IN_A_ROW . ' failed in a row: WHMCS is not answering, the other ' . count($left) . ' not asked. Run the import again.';
                    break;
                }
            }
            unset($password, $answer);
        }
        if ($failed !== []) {
            $this->notes[] = count($failed) . ' service password(s) could not be decrypted by WHMCS (service #' . implode(', #', array_slice($failed, 0, 50))
                . (count($failed) > 50 ? ', …' : '') . '): set them on their pages, or run the import again.';
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /** WHMCS's own message, after WhmcsApi's prefix: its words about the call, never a password (none was decrypted). */
    private function reason(Throwable $e): string
    {
        return mb_substr(trim((string) preg_replace('/^WHMCS API «[^»]*» failed \(HTTP \d+\): /u', '', $e->getMessage())), 0, 200);
    }
}
