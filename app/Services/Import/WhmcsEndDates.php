<?php

namespace App\Services\Import;

use App\Models\Service;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * When the services that ended in WHMCS ended (docs/growth-churn.md Γ1, WG-28): tblhosting.termination_date, and an
 * addon's tblhostingaddons.termination_date (else its parent's: addons end with it). Only on a service ended here whose
 * end is not known yet: never moves one MyCS set itself. Re-runnable; read-only on WHMCS. A WHMCS row without a date
 * leaves it empty: ended, not known when (the report counts those apart).
 */
final class WhmcsEndDates
{
    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{services: int, addons: int, from_parent: int, unknown: int} */
    public function run(): array
    {
        $services = $this->fill($this->dates('SELECT id, termination_date FROM tblhosting'), 'id');
        $addons = $this->fill($this->dates('SELECT id, termination_date FROM tblhostingaddons'), 'whmcs_addon_id');

        // An addon WHMCS gave no date of its own: its parent's end, when the parent ended too.
        $fromParent = 0;
        foreach (self::endedWithout()->whereNotNull('parent_service_id')->pluck('parent_service_id', 'id') as $id => $parent) {
            $at = DB::table('services')->where('id', $parent)->whereIn('status', Service::ENDED_STATUSES)->value('ended_at');
            if ($at !== null) {
                $fromParent += DB::table('services')->where('id', $id)->whereNull('ended_at')->update(['ended_at' => $at]);
            }
        }

        return ['services' => $services, 'addons' => $addons, 'from_parent' => $fromParent, 'unknown' => self::endedWithout()->count()];
    }

    /**
     * WHMCS id => its termination date, the rows that have one. An older WHMCS without the column: none.
     *
     * @return array<int, string>
     */
    private function dates(string $sql): array
    {
        try {
            $rows = $this->whmcs->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $id => $date) {
            $date = substr(trim((string) $date), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date > '1970-01-01' && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                $out[(int) $id] = $date . ' 00:00:00';
            }
        }

        return $out;
    }

    /** @param  array<int, string>  $dates */
    private function fill(array $dates, string $key): int
    {
        $filled = 0;
        foreach (array_chunk($dates, 500, true) as $chunk) {
            foreach (self::endedWithout()->whereIn($key, array_keys($chunk))->pluck($key, 'id') as $id => $whmcsId) {
                $filled += DB::table('services')->where('id', $id)->update(['ended_at' => $chunk[(int) $whmcsId]]);
            }
        }

        return $filled;
    }

    private static function endedWithout(): Builder
    {
        return DB::table('services')->whereIn('status', Service::ENDED_STATUSES)->whereNull('ended_at');
    }
}
