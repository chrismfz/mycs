<?php

namespace App\Services\Import;

use App\Models\Service;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PDO;
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

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        $live = DB::table('services')->whereIn('status', self::LIVE)->whereNull('password')->pluck('id')->flip()->all();
        $rows = array_filter($this->whmcs->query('SELECT id, password FROM tblhosting')->fetchAll(PDO::FETCH_ASSOC),
            fn (array $row) => isset($live[(int) $row['id']]) && trim((string) ($row['password'] ?? '')) !== '');
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
        foreach ($rows as $row) {
            try {
                $answer = $this->api->call('DecryptPassword', ['password2' => (string) $row['password']]);
                $password = $answer['password'] ?? null;
                if (!is_string($password) || $password === '') {
                    throw new RuntimeException('no password in the answer');
                }
                DB::table('services')->where('id', (int) $row['id'])->whereNull('password')
                    ->update(['password' => Crypt::encryptString($password)]);
                $this->counts['imported']++;
            } catch (Throwable $e) {
                // Its class only: an answer's message could carry what was asked.
                report(new RuntimeException("Service #{$row['id']}'s password not imported: " . class_basename($e)));
                $failed[] = (int) $row['id'];
                $this->counts['skipped']++;
            }
            unset($password, $answer);
        }
        if ($failed !== []) {
            $this->notes[] = count($failed) . ' service password(s) could not be decrypted by WHMCS (service #' . implode(', #', array_slice($failed, 0, 50))
                . (count($failed) > 50 ? ', …' : '') . '): set them on their pages, or run the import again.';
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }
}
