<?php

namespace App\Services\Licensing;

use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nixpal\Licensing\WhmcsLicenseRepository;
use PDO;
use Throwable;

/**
 * nixpal's licenses from WHMCS (L3, docs/licensing.md), after its products and services are imported (same ids): the
 * Licensing Addon's mod_licensing rows (same keys, bindings, reissues, status, whmcs_id), the products' module settings
 * (configoptions; the PSK encrypted, never printed), the custom fields zlic.php reports (as the service's properties),
 * WHMCS's billing cycle (verify.php's tag) and the last days of mod_licensinglog. Re-runnable: rows are matched by key.
 * LicensingImportParityTest proves zlic.php answers the imported licenses byte-for-byte as WHMCS does.
 */
final class WhmcsLicensingImport
{
    /** @var array{products: int, licenses: int, skipped: int, fields: int, logs: int} */
    private array $counts = ['products' => 0, 'licenses' => 0, 'skipped' => 0, 'fields' => 0, 'logs' => 0];

    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{products: int, licenses: int, skipped: int, fields: int, logs: int} */
    public function run(int $logDays = 90): array
    {
        if (!$this->hasTable('mod_licensing')) {
            return $this->counts;
        }
        $server = Server::query()->firstOrCreate(['extension' => 'NixpalLicensing'], ['name' => 'nixpal Licensing', 'type' => 'server', 'enabled' => true]);

        $products = $this->products($server);
        $licenses = $this->licenses($server, $products);
        $this->customFields($products);
        if ($logDays > 0 && $this->hasTable('mod_licensinglog')) {
            $this->logs($licenses, $logDays);
        }

        return $this->counts;
    }

    /** @return list<int> the MyCS products that sell licenses */
    private function products(Server $server): array
    {
        $rows = $this->whmcs->query("SELECT * FROM tblproducts WHERE servertype = 'licensing'
            OR id IN (SELECT h.packageid FROM tblhosting h JOIN mod_licensing l ON l.serviceid = h.id)")->fetchAll(PDO::FETCH_ASSOC);
        $ids = [];
        foreach ($rows as $row) {
            $product = Product::query()->find((int) $row['id']);
            if ($product === null) {
                continue;
            }
            $product->update(['server_id' => $server->id]);
            $settings = [
                'key_length' => [(string) ((int) ($row['configoption1'] ?? 0) ?: 16), 'string', false],
                'key_prefix' => [(string) ($row['configoption2'] ?? ''), 'string', false],
                'allow_reissue' => [($row['configoption3'] ?? '') === 'on' ? '1' : '0', 'boolean', false],
                'allow_ip_conflict' => [($row['configoption5'] ?? '') === 'on' ? '1' : '0', 'boolean', false],
                'custom_fields' => [implode(',', $this->fieldNames((int) $row['id'])), 'string', false],
            ];
            // An empty PSK never overwrites one staff typed.
            if (($row['configoption8'] ?? '') !== '') {
                $settings['secret_key'] = [(string) $row['configoption8'], 'string', true];
            }
            foreach ($settings as $key => [$value, $type, $encrypted]) {
                $setting = Setting::query()->firstOrNew(['settingable_type' => $product->getMorphClass(), 'settingable_id' => $product->id, 'key' => $key]);
                $setting->forceFill(['value' => $value, 'type' => $type, 'encrypted' => $encrypted])->save();
            }
            $ids[] = $product->id;
            $this->counts['products']++;
        }

        return $ids;
    }

    /**
     * mod_licensing as it is; WHMCS's empty bindings are null here (unbound).
     *
     * @param  list<int>  $products
     * @return array<int, int> mod_licensing.id => licenses.id
     */
    private function licenses(Server $server, array $products): array
    {
        $rows = $this->whmcs->query('SELECT l.*, h.billingcycle FROM mod_licensing l LEFT JOIN tblhosting h ON h.id = l.serviceid ORDER BY l.id')->fetchAll(PDO::FETCH_ASSOC);
        $services = Service::query()->whereIn('id', array_column($rows, 'serviceid'))->pluck('id')->flip();
        foreach ($rows as $row) {
            $serviceId = (int) $row['serviceid'];
            if (!isset($services[$serviceId])) {
                $this->counts['skipped']++; // its service was not imported

                continue;
            }
            DB::table('licenses')->upsert([[
                'service_id' => $serviceId,
                'license_key' => (string) $row['licensekey'],
                'status' => (string) $row['status'],
                'valid_domain' => self::text($row['validdomain'] ?? null),
                'valid_ip' => self::text($row['validip'] ?? null),
                'valid_directory' => self::text($row['validdirectory'] ?? null),
                'reissues' => (int) $row['reissues'],
                'last_access' => self::date($row['lastaccess'] ?? null),
                'whmcs_id' => (int) $row['id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['license_key'], ['service_id', 'status', 'valid_domain', 'valid_ip', 'valid_directory', 'reissues', 'last_access', 'whmcs_id', 'updated_at']);

            // The key is the service's domain (as WHMCS keeps it), on the license module's server.
            DB::table('services')->where('id', $serviceId)->where(fn ($query) => $query->whereNull('domain')->orWhere('domain', ''))
                ->update(['domain' => (string) $row['licensekey']]);
            DB::table('services')->where('id', $serviceId)->whereNull('server_id')->update(['server_id' => $server->id]);
            // verify.php's «billingcycle» is WHMCS's own, also where the plan here has none (a free or retired one).
            if (($row['billingcycle'] ?? '') !== '') {
                $this->property($serviceId, 'whmcs_billingcycle', 'WHMCS billing cycle', (string) $row['billingcycle']);
            }
            $this->counts['licenses']++;
        }

        return DB::table('licenses')->whereNotNull('whmcs_id')->pluck('id', 'whmcs_id')->mapWithKeys(fn ($id, $whmcsId) => [(int) $whmcsId => (int) $id])->all();
    }

    /**
     * The product custom fields zlic.php reports, as WhmcsLicenseRepository reads them (not the one
     * prepare_whmcs_copy.sh adds to a copy).
     *
     * @return list<string>
     */
    private function fieldNames(int $productId): array
    {
        $stmt = $this->whmcs->prepare("SELECT fieldname FROM tblcustomfields WHERE type = 'product' AND relid = ?
            AND NOT (fieldname = ? AND COALESCE(description, '') = ?) ORDER BY sortorder, id");
        $stmt->execute([$productId, WhmcsLicenseRepository::PREPARE_FIELD, WhmcsLicenseRepository::PREPARE_FIELD_DESCRIPTION]);

        return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), fn ($name) => !str_contains($name, ',')));
    }

    /** @param list<int> $products */
    private function customFields(array $products): void
    {
        foreach ($products as $productId) {
            $names = $this->fieldNames($productId);
            if ($names === []) {
                continue;
            }
            $stmt = $this->whmcs->prepare("SELECT f.fieldname, v.relid, v.value FROM tblcustomfields f JOIN tblcustomfieldsvalues v ON v.fieldid = f.id
                WHERE f.type = 'product' AND f.relid = ?");
            $stmt->execute([$productId]);
            $rows = array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), fn ($row) => in_array($row['fieldname'], $names, true) && (string) $row['value'] !== '');
            $services = Service::query()->where('product_id', $productId)->whereIn('id', array_column($rows, 'relid'))->pluck('id')->flip();
            foreach ($rows as $row) {
                if (isset($services[(int) $row['relid']])) {
                    $this->property((int) $row['relid'], (string) $row['fieldname'], (string) $row['fieldname'], (string) $row['value']);
                    $this->counts['fields']++;
                }
            }
        }
    }

    /**
     * The last days of mod_licensinglog (MyCS keeps 90). A re-run replaces what an earlier one brought: the imported
     * licenses' rows up to the newest WHMCS row.
     *
     * @param  array<int, int>  $licenses  mod_licensing.id => licenses.id
     */
    private function logs(array $licenses, int $days): void
    {
        $stmt = $this->whmcs->prepare('SELECT * FROM mod_licensinglog WHERE datetime >= ? ORDER BY id');
        $stmt->execute([now()->subDays($days)->format('Y-m-d H:i:s')]);
        $rows = array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), fn ($row) => isset($licenses[(int) $row['licenseid']]));
        if ($rows === []) {
            return;
        }
        $newest = max(array_map(fn ($row) => (string) $row['datetime'], $rows));
        DB::table('license_access_logs')->whereIn('license_id', array_values($licenses))->where('created_at', '<=', $newest)->delete();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('license_access_logs')->insert(array_map(fn ($row) => [
                'license_id' => $licenses[(int) $row['licenseid']],
                'domain' => mb_substr((string) $row['domain'], 0, 255),
                'ip' => mb_substr((string) $row['ip'], 0, 64),
                'directory' => mb_substr((string) $row['path'], 0, 255),
                'message' => mb_substr((string) $row['message'], 0, 255),
                'created_at' => $row['datetime'],
            ], $chunk));
            $this->counts['logs'] += count($chunk);
        }
    }

    private function property(int $serviceId, string $key, string $name, string $value): void
    {
        DB::table('properties')->upsert([['model_type' => Service::class, 'model_id' => $serviceId, 'key' => $key, 'name' => $name, 'value' => $value,
            'created_at' => now(), 'updated_at' => now()]], ['key', 'model_id', 'model_type'], ['name', 'value', 'updated_at']);
    }

    private function hasTable(string $table): bool
    {
        try {
            $this->whmcs->query("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function date(mixed $value): ?string
    {
        $value = (string) $value;
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
