<?php

namespace App\Admin;

use App\Admin\Pages\CronStats;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\DomainResource;
use App\Admin\Resources\FailedJobResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\McpProposalResource;
use App\Admin\Resources\ModuleActionResource;
use App\Admin\Resources\OrderResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\TicketQuarantineResource;
use App\Admin\Resources\TicketResource;
use App\Models\Invoice;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * The admin's sidebar on a desktop (owner 2026-10-21): not a second copy of the menu (the top menu is the whole map,
 * TopMenu) but what needs staff today, each with its count; a way to create; the records last opened. On a phone, where
 * the top menu is not, the sidebar keeps the full menu below these.
 */
class AdminSidebar
{
    /**
     * What waits on staff: [class, label, query (a list's tab or filter), own count?]; its count is the page's own
     * navigation badge, or [model, scope, color] for a queue the page does not count (overdue invoices).
     */
    public const QUEUES = [
        [TicketResource::class, 'Tickets awaiting reply', ['tab' => 'open']],
        [OrderResource::class, 'Pending orders', ['tab' => 'pending']],
        // Each opens the list of what it counts (review of #277), by the list's own tab or filter.
        [InvoiceResource::class, 'Unpaid invoices', ['filters' => ['status' => ['value' => 'unpaid']]]],
        // Of those, the late ones (owner 2026-10-21), counted by their own scope, not the page's badge.
        [InvoiceResource::class, 'Overdue invoices', ['filters' => ['overdue' => ['isActive' => true]]], [Invoice::class, 'overdue', 'danger']],
        [ServiceResource::class, 'Pending services', ['filters' => ['status' => ['value' => 'pending']]]],
        [ModuleActionResource::class, 'Pending module actions', []],
        [TicketQuarantineResource::class, 'Mail quarantine', []],
        [DomainResource::class, 'Domains to check', ['filters' => ['not_ours' => ['isActive' => true]]]],
        [McpProposalResource::class, 'MCP proposals', []],
        [FailedJobResource::class, 'Failed jobs', []],
        [CronStats::class, 'Automation status', []],
    ];

    /** Resources whose cluster's navigation badge is their own count (InvoiceCluster: the unpaid invoices). */
    private const COUNTED_BY_CLUSTER = [InvoiceResource::class];

    /** Quick create: [resource, label]. */
    public const CREATE = [
        [ClientResource::class, 'New client'],
        [OrderResource::class, 'New order'],
        [InvoiceResource::class, 'New invoice'],
        [TicketResource::class, 'New ticket'],
    ];

    /** How many records «Recently viewed» keeps. */
    public const RECENT = 6;

    private const SESSION = 'admin.recent';

    /** Each staff member's own: two in one browser never see each other's records (review: DomainsModelTest). */
    private static function key(): string
    {
        return self::SESSION . '.' . (auth('staff')->id() ?? 0);
    }

    /** @return list<array{label: string, url: string, badge: ?string, color: string}> the queues this staff member may open */
    public static function queues(): array
    {
        $counted = self::navigationBadges();
        $queues = [];
        foreach (self::QUEUES as $queue) {
            [$class, $label, $query] = $queue;
            $own = $queue[3] ?? null;
            try {
                if (is_subclass_of($class, Resource::class) && $class::canAccess()) {
                    $base = $class::getUrl('index');
                    $url = $class::getUrl('index', $query);
                } elseif (is_subclass_of($class, Page::class) && $class::canAccess()) {
                    $base = $url = $class::getUrl();
                } else {
                    continue;
                }
                // The count Filament's navigation made in this request; counted again only for a page not in it.
                // A resource inside a cluster that counts the same is in the navigation as its cluster (invoices).
                $clusterUrl = in_array($class, self::COUNTED_BY_CLUSTER, true) ? $class::getCluster()::getUrl() : null;
                if ($own !== null) {
                    // A queue with its own count: [model, scope, color].
                    [$model, $scope, $ownColor] = $own;
                    $count = $model::query()->{$scope}()->count();
                    [$badge, $color] = [$count > 0 ? $count : null, $ownColor];
                } else {
                    [$badge, $color] = $counted[$base] ?? ($clusterUrl !== null ? ($counted[$clusterUrl] ?? null) : null)
                        ?? [$class::getNavigationBadge(), $class::getNavigationBadgeColor()];
                }
                $queues[] = ['label' => $label, 'url' => $url, 'badge' => $badge === null ? null : (string) $badge, 'color' => $color ?? 'gray'];
            } catch (\Throwable) {
                // A page that cannot build its URL right now is left out, as in the top menu.
            }
        }

        return $queues;
    }

    /**
     * The badges of the navigation Filament built for this page (its items are mounted once a request, each badge
     * already counted): url => [badge, color] (review of #277: every queue was counted twice).
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function navigationBadges(): array
    {
        $badges = [];
        try {
            foreach (Filament::getNavigation() as $group) {
                foreach ($group->getItems() as $item) {
                    foreach ([$item, ...$item->getChildItems()] as $one) {
                        $badges[(string) $one->getUrl()] = [$one->getBadge(), $one->getBadgeColor()];
                    }
                }
            }
        } catch (\Throwable) {
            // No panel navigation here: each page counts itself.
        }

        return $badges;
    }

    /** @return list<array{label: string, url: string}> */
    public static function creates(): array
    {
        $creates = [];
        foreach (self::CREATE as [$class, $label]) {
            if ($class::canCreate() && $class::hasPage('create')) {
                $creates[] = ['label' => $label, 'url' => $class::getUrl('create')];
            }
        }

        return $creates;
    }

    /** A record staff opened, kept for «Recently viewed» (this session only; the newest first, each once). */
    /** @param  class-string<resource>  $resource */
    public static function remember(string $resource, Model $record, string $label, string $url): void
    {
        $key = $resource . ':' . $record->getKey();
        $recent = array_values(array_filter(session(self::key(), []), fn (array $item) => ($item['key'] ?? null) !== $key));
        array_unshift($recent, ['key' => $key, 'resource' => $resource, 'kind' => (string) $resource::getModelLabel(), 'label' => mb_substr($label, 0, 80), 'url' => $url]);
        session([self::key() => array_slice($recent, 0, self::RECENT)]);
    }

    /**
     * What this staff member opened and may still open (review of #277: a name stayed after the right to see it went).
     *
     * @return list<array{key: string, resource: string, kind: string, label: string, url: string}>
     */
    public static function recent(): array
    {
        return array_values(array_filter(session(self::key(), []), function (array $item) {
            $resource = $item['resource'] ?? null;

            return is_string($resource) && is_subclass_of($resource, Resource::class) && $resource::canAccess();
        }));
    }
}
