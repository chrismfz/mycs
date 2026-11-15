<?php

namespace App\Mcp\Tools;

use App\Admin\Resources\LicenseResource;
use App\Admin\Resources\LicenseResource\Pages\ListLicenses;
use App\Models\License;
use App\Models\LicenseInstall;
use App\Services\Licensing\LicenseTelemetry;
use App\Services\Ops\Scrub;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The licenses as a whole (docs/licensing.md): how many in each of the list's tabs, which versions of the controller,
 * agent and node run (what /licensing/v1/check was told, installs heard of in the last `days`), through which endpoint
 * the checks come, and the signals. With `product` and `version`: which licenses run that version, e.g. whom to tell to
 * update. Keys masked (prefix…last4), as everywhere in the MCP.
 */
#[Name('licenses')]
#[Description('The licenses as a whole: how many are live, silent, expired but calling, went quiet, dormant; which versions of the controller, agent and node run, and how many installs each (from /licensing/v1/check, heard of in the last `days`); how many licenses checked through the new endpoint and how many still through zlic.php (older software that sends no version); the signals (downgrades, a token that did not verify, a clock far off, a license in more than one place). With product and version: the licenses that run that version (whom to tell to update). Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class LicensesTool extends McpTool
{
    /** What Client::name reads, loaded with the licenses (as the list, review R2). */
    private const CLIENT = ['service.client.primaryContact', 'service.client.ownerGrant.user'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('Installs and checks heard of in this many days (default 7, at most 90).'),
            'product' => $schema->string()->description('controller, agent or node: list the licenses running `version` of it (or any version), one row per license, the most recently heard first.'),
            'version' => $schema->string()->description('With product: only this version (e.g. 2.5.1).'),
            'limit' => $schema->integer()->description('Rows in a list (default 25, at most 100).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.services.viewAny';
    }

    protected function run(Request $request): array
    {
        $days = max(1, min(90, (int) ($request->get('days') ?? LicenseResource::INSTALLS_DAYS)));
        $since = now()->subDays($days);
        $limit = self::limit($request, 25, 100);
        $product = strtolower(trim((string) $request->get('product')));
        if ($product !== '' && !in_array($product, LicenseTelemetry::PRODUCTS, true)) {
            return ['error' => 'product is one of: ' . implode(', ', LicenseTelemetry::PRODUCTS)];
        }

        $recent = LicenseInstall::query()->where('last_seen_at', '>=', $since)->where('product', '!=', '');
        if ($product !== '') {
            $version = trim((string) $request->get('version'));
            // One row per license (whom to tell), with what its installs of the product run; the most recently heard first.
            $licenses = License::query()->with(self::CLIENT)
                ->whereIn('id', (clone $recent)->where('product', $product)->when($version !== '', fn ($q) => $q->where('version', $version))->select('license_id'))
                ->with(['installs' => fn ($q) => $q->where('last_seen_at', '>=', $since)->where('product', $product)
                    ->when($version !== '', fn ($v) => $v->where('version', $version))->orderByDesc('last_seen_at')])
                ->get()->sortByDesc(fn (License $license) => $license->installs->first()?->last_seen_at)->take($limit)->values();

            return [
                'days' => $days,
                'product' => $product,
                'version' => $version !== '' ? $version : null,
                'licenses' => $licenses->map(fn (License $license) => [
                    'license' => Scrub::licenseKey($license->license_key),
                    'service_id' => $license->service_id,
                    'client' => $license->service?->client?->name,
                    'status' => $license->status,
                    'installs' => $license->installs->take(5)->map(fn (LicenseInstall $install) => LicenseTelemetry::describe($install))->all(),
                ])->all(),
            ];
        }

        $versions = self::versions($since);

        $filters = ListLicenses::filters();
        $recentInstalls = now()->subDays(LicenseResource::INSTALLS_DAYS);
        $signals = $filters['signals'](License::query())->with(self::CLIENT)
            ->with(['installs' => fn ($q) => $q->where('last_seen_at', '>=', $recentInstalls)->orderByDesc('last_seen_at')])->limit($limit)->get();

        return [
            'days' => $days,
            'tabs' => array_map(fn (\Closure $filter) => $filter(License::query())->count(), $filters) + ['all' => License::query()->count()],
            'versions' => $versions,
            // The last check from where each license runs: v1 sends what it is; v0 (zlic.php) is older software that does not.
            'endpoints' => License::query()->where('last_access', '>=', $since)->whereNotNull('last_endpoint')
                ->selectRaw('last_endpoint, COUNT(*) AS licenses')->groupBy('last_endpoint')->pluck('licenses', 'last_endpoint')
                ->mapWithKeys(fn ($count, $endpoint) => [$endpoint === 'v1' ? 'v1 (/licensing/v1/check)' : 'v0 (zlic.php)' => (int) $count])->all(),
            'signals' => $signals->map(fn (License $license) => [
                'license' => Scrub::licenseKey($license->license_key),
                'service_id' => $license->service_id,
                'client' => $license->service?->client?->name,
                'installs' => $license->installs->take(5)->map(fn (LicenseInstall $install) => LicenseTelemetry::describe($install))->all(),
            ])->all(),
        ];
    }

    /**
     * Per product, the versions heard of since $since, newest first by version (2.10.0 above 2.8.0), each with its
     * installs and licenses.
     *
     * @return array<string, list<array{version: ?string, installs: int, licenses: int, last_seen: string}>>
     */
    public static function versions(\DateTimeInterface $since): array
    {
        $versions = [];
        foreach (LicenseInstall::query()->where('last_seen_at', '>=', $since)->where('product', '!=', '')
            ->selectRaw('product, version, COUNT(DISTINCT install_id) AS installs, COUNT(DISTINCT license_id) AS licenses, MAX(last_seen_at) AS last_seen')
            ->groupBy('product', 'version')->get() as $row) {
            $versions[$row->product][] = ['version' => $row->version, 'installs' => (int) $row->installs, 'licenses' => (int) $row->licenses, 'last_seen' => (string) $row->last_seen];
        }
        foreach ($versions as $name => $rows) {
            usort($rows, fn ($a, $b) => version_compare((string) $b['version'], (string) $a['version']));
            $versions[$name] = $rows;
        }

        return $versions;
    }
}
