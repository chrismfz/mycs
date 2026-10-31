<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * The names customers gave their services (the «Client Product Descriptions» addon: `mod_clientproductdescriptions`,
 * type tblhosting, pid the service), as the service's label, which the portal shows and searches. Only for services
 * that came; a blank one is none; WHMCS's HTML escaping undone; at most the 255 characters the label holds.
 */
final class WhmcsServiceLabelImport
{
    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        $counts = ['labels' => 0, 'skipped' => 0];
        try {
            $rows = $this->whmcs->query("SELECT pid, description FROM mod_clientproductdescriptions WHERE type = 'tblhosting' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return ['counts' => $counts, 'notes' => []];
        }
        $services = DB::table('services')->pluck('id')->flip()->all();
        foreach ($rows as $row) {
            $label = mb_substr(trim(html_entity_decode((string) $row['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 255);
            $id = (int) $row['pid'];
            if ($label === '' || !isset($services[$id])) {
                $counts['skipped']++;

                continue;
            }
            DB::table('services')->where('id', $id)->update(['label' => $label]);
            $counts['labels']++;
        }

        return ['counts' => $counts, 'notes' => []];
    }
}
