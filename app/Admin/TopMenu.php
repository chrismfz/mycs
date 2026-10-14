<?php

namespace App\Admin;

use App\Admin\Pages\CronStats;
use App\Admin\Pages\Extension;
use App\Admin\Pages\Settings;
use App\Admin\Resources\AdjustmentNoteResource;
use App\Admin\Resources\ApiResource;
use App\Admin\Resources\Audits\AuditResource;
use App\Admin\Resources\CategoryResource;
use App\Admin\Resources\ConfigOptionResource;
use App\Admin\Resources\CouponResource;
use App\Admin\Resources\CurrencyResource;
use App\Admin\Resources\CustomPropertyResource;
use App\Admin\Resources\EmailLogResource;
use App\Admin\Resources\ExtensionResource;
use App\Admin\Resources\FailedJobResource;
use App\Admin\Resources\GatewayResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceTransactions\InvoiceTransactionResource;
use App\Admin\Resources\NotificationTemplateResource;
use App\Admin\Resources\OauthClientResource;
use App\Admin\Resources\OrderResource;
use App\Admin\Resources\ProductResource;
use App\Admin\Resources\RoleResource;
use App\Admin\Resources\ServerGroupResource;
use App\Admin\Resources\ServerResource;
use App\Admin\Resources\ServiceCancellationResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\TaxRateResource;
use App\Admin\Resources\TicketResource;
use App\Admin\Resources\UserResource;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Str;

/**
 * The WHMCS-like top menu of the admin (docs/admin-ux.md). The sidebar stays as it is; the
 * top menu points at the same pages, so a page can be in both, and an item shows only when
 * its own canAccess()/canCreate() lets the staff member in (same permissions as the sidebar).
 */
class TopMenu
{
    /**
     * Group => items: [class] (its navigation label) or [class, page, label] for a resource
     * page such as 'create'. Order and names follow the WHMCS menu people are used to.
     *
     * @var array<string, list<array{0: class-string, 1?: string, 2?: string}>>
     */
    public const MENU = [
        'Clients' => [
            [UserResource::class, 'index', 'View/Search Clients'],
            [UserResource::class, 'create', 'Add New Client'],
            [ServiceResource::class, 'index', 'Products/Services'],
            [ServiceCancellationResource::class, 'index', 'Cancellation Requests'],
        ],
        'Orders' => [
            [OrderResource::class, 'index', 'List All Orders'],
            [OrderResource::class, 'create', 'Add New Order'],
            [CouponResource::class, 'index', 'Promotions'],
        ],
        'Billing' => [
            [InvoiceResource::class, 'index', 'Invoices'],
            [InvoiceResource::class, 'create', 'Create Invoice'],
            [InvoiceTransactionResource::class, 'index', 'Transactions List'],
            [AdjustmentNoteResource::class, 'index', 'Adjustment Notes'],
        ],
        'Support' => [
            [TicketResource::class, 'index', 'Support Tickets'],
            [TicketResource::class, 'create', 'Open New Ticket'],
        ],
        'Utilities' => [
            [EmailLogResource::class, 'index', 'Email Message Log'],
            [AuditResource::class, 'index', 'Activity Log'],
            [CronStats::class],
            [FailedJobResource::class, 'index', 'Failed Jobs'],
        ],
        'Setup' => [
            [Settings::class],
            [CategoryResource::class, 'index', 'Product Groups'],
            [ProductResource::class, 'index', 'Products/Services'],
            [ConfigOptionResource::class, 'index', 'Configurable Options'],
            [CustomPropertyResource::class, 'index', 'Custom Client Fields'],
            [CurrencyResource::class, 'index', 'Currencies'],
            [TaxRateResource::class, 'index', 'Tax Rules'],
            [GatewayResource::class, 'index', 'Payment Gateways'],
            [ServerResource::class, 'index', 'Servers'],
            [ServerGroupResource::class, 'index', 'Server Groups'],
            [NotificationTemplateResource::class, 'index', 'Email Templates'],
            [RoleResource::class, 'index', 'Staff Roles'],
            [ExtensionResource::class, 'index', 'Extensions'],
            [Extension::class],
            [ApiResource::class, 'index', 'API Credentials'],
            [OauthClientResource::class, 'index', 'OAuth Clients'],
        ],
    ];

    /**
     * The visible menu for the current staff member; groups with no visible item are dropped.
     *
     * @return list<array{label: string, active: bool, items: list<array{label: string, url: string, active: bool}>}>
     */
    public static function groups(): array
    {
        $current = request()->url();
        $groups = [];
        foreach (self::MENU as $label => $entries) {
            $items = [];
            foreach ($entries as $entry) {
                if ($item = self::item(...$entry)) {
                    $item['active'] = $current === $item['url'] || Str::startsWith($current, $item['url'] . '/');
                    $items[] = $item;
                }
            }
            if ($items !== []) {
                $groups[] = [
                    'label' => $label,
                    'active' => collect($items)->contains('active', true),
                    'items' => $items,
                ];
            }
        }

        return $groups;
    }

    /**
     * @param  class-string  $class
     * @return array{label: string, url: string}|null
     */
    private static function item(string $class, string $page = 'index', ?string $label = null): ?array
    {
        try {
            if (is_subclass_of($class, Resource::class)) {
                $allowed = $page === 'create' ? $class::canCreate() : $class::canAccess();
                if (!$allowed || !$class::hasPage($page)) {
                    return null;
                }

                return ['label' => $label ?? $class::getNavigationLabel(), 'url' => $class::getUrl($page)];
            }
            if (is_subclass_of($class, Page::class) && $class::canAccess()) {
                return ['label' => $label ?? $class::getNavigationLabel(), 'url' => $class::getUrl()];
            }
        } catch (\Throwable) {
            // A page that cannot build its URL right now (e.g. a conditional resource) is left out.
        }

        return null;
    }
}
