<?php

namespace App\Admin;

use App\Admin\Pages\CronStats;
use App\Admin\Resources\Audits\AuditResource;
use App\Admin\Resources\EmailLogResource;
use App\Admin\Resources\ErrorLogResource;
use App\Admin\Resources\FailedJobResource;
use App\Admin\Resources\HttpLogResource;
use App\Admin\Resources\McpCallResource;
use App\Admin\Resources\ModuleActionResource;
use App\Admin\Resources\PaymentGatewayEventResource;
use App\Admin\Resources\TicketQuarantineResource;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Resources\Resource;

/**
 * The logs in one place, as WHMCS's Utilities → Logs (owner 2026-10-23): each log page shows them all on its left. The
 * pages stay where they were (their URLs, their menus); an item shows only to whom its page lets in.
 */
final class Logs
{
    /** Group => [class, label]: a resource's list or a page. */
    public const ITEMS = [
        'Activity' => [
            [AuditResource::class, 'Activity Log'],
            [ModuleActionResource::class, 'Module Actions'],
            [CronStats::class, 'Automation Status'],
        ],
        'Mail' => [
            [EmailLogResource::class, 'Email Message Log'],
            [TicketQuarantineResource::class, 'Mail Quarantine'],
        ],
        'Payments' => [
            [PaymentGatewayEventResource::class, 'Gateway Log'],
        ],
        'System' => [
            [McpCallResource::class, 'MCP Calls'],
            [FailedJobResource::class, 'Failed Jobs'],
            [ErrorLogResource::class, 'Error Logs'],
            [HttpLogResource::class, 'HTTP Logs'],
        ],
    ];

    /** @return list<NavigationItem> */
    public static function navigation(): array
    {
        $items = [];
        $sort = 0;
        foreach (self::ITEMS as $group => $entries) {
            foreach ($entries as [$class, $label]) {
                if (!$class::canAccess()) {
                    continue;
                }
                $resource = is_subclass_of($class, Resource::class);
                /** @var class-string<Page>|class-string<resource> $class */
                $items[] = NavigationItem::make(__($label))
                    ->group(__($group))
                    ->icon($class::getNavigationIcon())
                    ->url($resource ? $class::getUrl('index') : $class::getUrl())
                    ->isActiveWhen(fn () => request()->routeIs($resource ? $class::getRouteBaseName() . '.*' : $class::getRouteName()))
                    ->sort($sort++);
            }
        }

        return $items;
    }
}
