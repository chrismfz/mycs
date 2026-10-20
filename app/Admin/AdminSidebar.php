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
    /** What waits on staff: [class, label, query (a list's tab)]; its count is the page's own navigation badge. */
    public const QUEUES = [
        [TicketResource::class, 'Tickets awaiting reply', ['tab' => 'open']],
        [OrderResource::class, 'Pending orders', ['tab' => 'pending']],
        [InvoiceResource::class, 'Unpaid invoices', []],
        [ServiceResource::class, 'Pending services', []],
        [ModuleActionResource::class, 'Pending module actions', []],
        [TicketQuarantineResource::class, 'Mail quarantine', []],
        [DomainResource::class, 'Domains to check', []],
        [McpProposalResource::class, 'MCP proposals', []],
        [FailedJobResource::class, 'Failed jobs', []],
        [CronStats::class, 'Automation status', []],
    ];

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
        $queues = [];
        foreach (self::QUEUES as [$class, $label, $query]) {
            try {
                if (is_subclass_of($class, Resource::class) && $class::canAccess()) {
                    $url = $class::getUrl('index', $query);
                } elseif (is_subclass_of($class, Page::class) && $class::canAccess()) {
                    $url = $class::getUrl();
                } else {
                    continue;
                }
                $queues[] = ['label' => $label, 'url' => $url, 'badge' => $class::getNavigationBadge(), 'color' => $class::getNavigationBadgeColor() ?? 'gray'];
            } catch (\Throwable) {
                // A page that cannot build its URL right now is left out, as in the top menu.
            }
        }

        return $queues;
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
    public static function remember(string $kind, Model $record, string $label, string $url): void
    {
        $key = $kind . ':' . $record->getKey();
        $recent = array_values(array_filter(session(self::key(), []), fn (array $item) => ($item['key'] ?? null) !== $key));
        array_unshift($recent, ['key' => $key, 'kind' => $kind, 'label' => mb_substr($label, 0, 80), 'url' => $url]);
        session([self::key() => array_slice($recent, 0, self::RECENT)]);
    }

    /** @return list<array{key: string, kind: string, label: string, url: string}> */
    public static function recent(): array
    {
        return session(self::key(), []);
    }
}
