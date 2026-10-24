<?php

namespace App\Admin\Pages;

use App\Helpers\ExtensionHelper;
use App\Models\ProxmoxGuest;
use App\Services\Proxmox\GuestSync;
use App\Support\Redact;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use Throwable;

/**
 * Utilities → Proxmox Nodes (owner 2026-10-24): each cluster's nodes as Proxmox shows them, their CPU, memory, IO wait
 * and network over the last hour, day or week, read live (kept a minute) with the clusters' read-only token.
 */
class ProxmoxNodes extends Page
{
    public const TIMEFRAMES = ['hour' => 'Hour', 'day' => 'Day', 'week' => 'Week'];

    protected static string|\UnitEnum|null $navigationGroup = 'Utilities';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-server-line';

    protected static ?string $navigationLabel = 'Proxmox Nodes';

    protected static ?string $title = 'Proxmox Nodes';

    protected static string $routePath = 'proxmox-nodes';

    protected string $view = 'admin.pages.proxmox-nodes';

    #[Url]
    public string $timeframe = 'hour';

    public static function canAccess(): bool
    {
        return (bool) Auth::guard('staff')->user()?->hasPermission('admin.servers.viewAny');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * Each cluster: its nodes with their graphs, or why it could not be read.
     *
     * @return list<array{name: string, error: ?string, nodes: list<array<string, mixed>>}>
     */
    public function clusters(): array
    {
        $timeframe = array_key_exists($this->timeframe, self::TIMEFRAMES) ? $this->timeframe : 'hour';
        $clusters = [];
        foreach (GuestSync::servers() as $server) {
            try {
                $nodes = Cache::remember("proxmox.nodes.{$server->id}.{$timeframe}", 60, function () use ($server, $timeframe) {
                    $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
                    if (!$extension instanceof Proxmox) {
                        return [];
                    }
                    $client = $extension->client();
                    $nodes = [];
                    foreach ($client->nodes() as $node) {
                        $name = (string) ($node['node'] ?? '');
                        $points = [];
                        if (($node['status'] ?? '') === 'online') {
                            try {
                                $points = $client->nodeRrd($name, $timeframe);
                            } catch (Throwable) {
                                // its graphs only
                            }
                        }
                        $nodes[] = ['node' => $node, 'series' => self::series($points)];
                    }
                    usort($nodes, fn ($a, $b) => strcmp((string) $a['node']['node'], (string) $b['node']['node']));

                    return $nodes;
                });
                $clusters[] = ['name' => $server->name, 'error' => null, 'nodes' => $nodes];
            } catch (Throwable $e) {
                $clusters[] = ['name' => $server->name, 'error' => Redact::text($e->getMessage()), 'nodes' => []];
            }
        }

        return $clusters;
    }

    /** @return array<string, int> node => guests on it mapped/unmapped, from the last sync */
    public function unmappedByNode(): array
    {
        return ProxmoxGuest::query()->unmapped()->whereNull('gone_at')->selectRaw('node, count(*) as n')->groupBy('node')->pluck('n', 'node')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * The graph lines: CPU and IO wait in %, memory in % of the node's, network in bytes/s.
     *
     * @param  list<array<string, mixed>>  $points
     * @return array<string, list<float|null>>
     */
    public static function series(array $points): array
    {
        $series = ['cpu' => [], 'iowait' => [], 'memory' => [], 'netin' => [], 'netout' => []];
        foreach ($points as $p) {
            $series['cpu'][] = isset($p['cpu']) ? round((float) $p['cpu'] * 100, 1) : null;
            $series['iowait'][] = isset($p['iowait']) ? round((float) $p['iowait'] * 100, 1) : null;
            $series['memory'][] = isset($p['memused'], $p['memtotal']) && (float) $p['memtotal'] > 0 ? round((float) $p['memused'] / (float) $p['memtotal'] * 100, 1) : null;
            $series['netin'][] = isset($p['netin']) ? (float) $p['netin'] : null;
            $series['netout'][] = isset($p['netout']) ? (float) $p['netout'] : null;
        }

        return $series;
    }

    /**
     * An SVG polyline's points for values on a 300×60 box, from 0 to $max (the values' own max when null); gaps skipped.
     *
     * @param  list<float|null>  $values
     */
    public static function points(array $values, ?float $max = null): string
    {
        $known = array_filter($values, fn ($v) => $v !== null);
        if ($known === []) {
            return '';
        }
        $max = $max ?? max(max($known), 1e-9);
        $count = max(count($values) - 1, 1);
        $points = [];
        foreach ($values as $i => $v) {
            if ($v !== null) {
                $points[] = round($i / $count * 300, 1) . ',' . round(60 - min($v / $max, 1) * 60, 1);
            }
        }

        return implode(' ', $points);
    }

    /** @param  list<float|null>  $values */
    public static function last(array $values): ?float
    {
        $known = array_values(array_filter($values, fn ($v) => $v !== null));

        return $known === [] ? null : end($known);
    }
}
