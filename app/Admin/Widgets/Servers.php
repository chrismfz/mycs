<?php

namespace App\Admin\Widgets;

use App\Admin\Resources\ServerResource;
use App\Models\Server;
use App\Models\Service;
use Filament\Widgets\Widget;
use Illuminate\View\View;

/**
 * «Servers» (owner 2026-11-03, from the GaiaCP demo): each enabled server's accounts against its «max accounts», as the
 * servers list counts them (Server::liveAccountsOn: active, suspended, pending). No uptime: MyCS has no such data and
 * monitoring is not its job. The fullest first; a server without a limit shows its count, without a bar.
 */
class Servers extends Widget
{
    // At the top of the right column of the dashboard (`Dashboard::RIGHT`), above Support.
    protected static ?int $sort = 4;

    protected string $view = 'admin.widgets.servers';

    /** From this share of the limit the bar is amber, and from FULL red. */
    public const NEARLY = 0.75;

    public const FULL = 0.9;

    public function render(): View
    {
        // One count for all the servers, not one per server.
        $counts = Service::query()->whereNotNull('server_id')
            ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, Service::STATUS_PENDING])
            ->selectRaw('server_id, count(*) as accounts')->groupBy('server_id')->pluck('accounts', 'server_id');

        $rows = Server::query()->where('enabled', true)->orderBy('name')->get()
            ->map(function (Server $server) use ($counts): array {
                $used = (int) ($counts[$server->id] ?? 0);
                $max = $server->max_accounts ? (int) $server->max_accounts : null;
                $share = $max ? $used / $max : null;

                return [
                    'name' => $server->name,
                    'module' => $server->extension,
                    'used' => $used,
                    'max' => $max,
                    'share' => $share,
                    'percent' => $share === null ? null : (int) min(100, round($share * 100)),
                    'color' => match (true) {
                        $share === null => null,
                        $share >= self::FULL => 'danger',
                        $share >= self::NEARLY => 'warning',
                        default => 'primary',
                    },
                    'url' => ServerResource::canEdit($server) ? ServerResource::getUrl('edit', ['record' => $server]) : null,
                ];
            })
            // By the share itself, not the rounded and capped percent: 6 of 4 before 10 of 10.
            ->sortBy([fn (array $a, array $b) => ($b['share'] ?? -1) <=> ($a['share'] ?? -1), fn (array $a, array $b) => $b['used'] <=> $a['used']])
            ->values()->all();

        return view($this->view, [
            'rows' => $rows,
            'url' => ServerResource::getUrl('index'),
        ]);
    }

    public static function canView(): bool
    {
        return ServerResource::canViewAny();
    }
}
