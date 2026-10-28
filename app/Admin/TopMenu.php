<?php

namespace App\Admin;

use App\Admin\Clusters\Extensions;
use App\Admin\Clusters\InvoiceCluster;
use App\Admin\Clusters\Services;
use App\Admin\Pages\Dashboard;
use App\Admin\Pages\Extension;
use App\Admin\Pages\GrRegistry;
use App\Admin\Pages\IncomeByClient;
use App\Admin\Pages\IncomeByProduct;
use App\Admin\Pages\IncomeReport;
use App\Admin\Pages\LiveChat;
use App\Admin\Pages\ProxmoxNodes;
use App\Admin\Pages\RevenueForecast;
use App\Admin\Pages\Settings;
use App\Admin\Pages\SslChecker;
use App\Admin\Pages\SystemCleanup;
use App\Admin\Pages\WhoisLookup;
use App\Admin\Resources\AdjustmentNoteResource;
use App\Admin\Resources\ApiResource;
use App\Admin\Resources\BulkMailResource;
use App\Admin\Resources\CannedReplyCategoryResource;
use App\Admin\Resources\CannedReplyResource;
use App\Admin\Resources\CategoryResource;
use App\Admin\Resources\ChatNotificationRuleResource;
use App\Admin\Resources\ClientGroupResource;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ConfigOptionResource;
use App\Admin\Resources\CouponResource;
use App\Admin\Resources\CpanelAccountResource;
use App\Admin\Resources\CurrencyResource;
use App\Admin\Resources\CustomPropertyResource;
use App\Admin\Resources\DomainRegistrarConnectionResource;
use App\Admin\Resources\DomainResource;
use App\Admin\Resources\DomainTldResource;
use App\Admin\Resources\ExtensionResource;
use App\Admin\Resources\GatewayResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceTransactions\InvoiceTransactionResource;
use App\Admin\Resources\IpPoolResource;
use App\Admin\Resources\KbArticleResource;
use App\Admin\Resources\KbCategoryResource;
use App\Admin\Resources\LicenseResource;
use App\Admin\Resources\MailcowDomainResource;
use App\Admin\Resources\McpOauthGrantResource;
use App\Admin\Resources\McpProposalResource;
use App\Admin\Resources\ModuleActionResource;
use App\Admin\Resources\NotificationTemplateResource;
use App\Admin\Resources\OfferResource;
use App\Admin\Resources\OrderResource;
use App\Admin\Resources\PaymentGatewayEventResource;
use App\Admin\Resources\ProductResource;
use App\Admin\Resources\ProxmoxGuestResource;
use App\Admin\Resources\RoleResource;
use App\Admin\Resources\ServerGroupResource;
use App\Admin\Resources\ServerResource;
use App\Admin\Resources\ServiceCancellationResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\StaffResource;
use App\Admin\Resources\TaxRateResource;
use App\Admin\Resources\TicketBlockedSenderResource;
use App\Admin\Resources\TicketDepartmentResource;
use App\Admin\Resources\TicketMailImportResource;
use App\Admin\Resources\TicketQuarantineResource;
use App\Admin\Resources\TicketResource;
use App\Admin\Resources\UserResource;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Str;

/**
 * The WHMCS-like top menu of the admin (docs/admin-ux.md): the whole map of the admin, every page of it (an
 * extension's under «Addons»); the sidebar is for what needs staff today. An item shows only when its
 * own canAccess()/canCreate() lets the staff member in.
 */
class TopMenu
{
    /**
     * Group => items: [class] (its navigation label) or [class, page, label] for a resource
     * page such as 'create'. Order and names follow the WHMCS menu people are used to.
     *
     * @var array<string, list<array{0: class-string, 1?: string, 2?: string, 3?: array<string, string>}>>
     */
    public const MENU = [
        'Clients' => [
            [ClientResource::class, 'index', 'View/Search Clients'],
            [ClientResource::class, 'create', 'Add New Client'],
            [UserResource::class, 'index', 'Manage Users'],
            [ServiceResource::class, 'index', 'Products/Services'],
            [DomainResource::class, 'index', 'Domains'],
            [ServiceCancellationResource::class, 'index', 'Cancellation Requests'],
            [LicenseResource::class, 'index', 'Licenses'],
            [ClientGroupResource::class, 'index', 'Client Groups'],
            [BulkMailResource::class, 'index', 'Mass Mail Tool'],
        ],
        'Orders' => [
            [OrderResource::class, 'index', 'List All Orders'],
            [OrderResource::class, 'index', 'Pending Orders', ['tab' => 'pending']],
            [OrderResource::class, 'create', 'Add New Order'],
            [CouponResource::class, 'index', 'Promotions'],
            [OfferResource::class, 'index', 'Offers'],
        ],
        'Billing' => [
            [InvoiceResource::class, 'index', 'Invoices'],
            [InvoiceResource::class, 'create', 'Create Invoice'],
            [InvoiceTransactionResource::class, 'index', 'Transactions List'],
            [AdjustmentNoteResource::class, 'index', 'Adjustment Notes'],
            [PaymentGatewayEventResource::class, 'index', 'Gateway Log'],
        ],
        'Support' => [
            [TicketResource::class, 'index', 'Support Tickets'],
            [TicketResource::class, 'create', 'Open New Ticket'],
            [LiveChat::class],
            [TicketQuarantineResource::class, 'index', 'Mail Quarantine'],
            [TicketMailImportResource::class, 'index', 'Ticket Mail Import Log'],
            [TicketBlockedSenderResource::class, 'index', 'Blocked Senders'],
            [CannedReplyResource::class, 'index', 'Predefined Replies'],
            [CannedReplyCategoryResource::class, 'index', 'Reply Categories'],
            [KbArticleResource::class, 'index', 'Knowledgebase'],
            [KbCategoryResource::class, 'index', 'Knowledgebase Categories'],
        ],
        'Reports' => [
            [RevenueForecast::class],
            [IncomeByClient::class],
            [IncomeByProduct::class],
            [IncomeReport::class],
        ],
        'Utilities' => [
            // All the logs behind one line (owner 2026-10-28): it opens the first, and each log lists them all on its left.
            [Logs::class, 'index', 'Logs'],
            [WhoisLookup::class],
            [SslChecker::class],
            [GrRegistry::class],
            [ModuleActionResource::class, 'index', 'Pending Module Actions'],
            [ProxmoxGuestResource::class, 'index', 'Proxmox Guests'],
            [IpPoolResource::class, 'index', 'IP Pools'],
            [ProxmoxNodes::class],
            [CpanelAccountResource::class, 'index', 'Hosting Accounts'],
            [MailcowDomainResource::class, 'index', 'Mail Domains'],
            [McpProposalResource::class, 'index', 'MCP Proposals'],
            [SystemCleanup::class],
        ],
        'Setup' => [
            [Settings::class],
            [CategoryResource::class, 'index', 'Product Groups'],
            [ProductResource::class, 'index', 'Products'],
            [ConfigOptionResource::class, 'index', 'Configurable Options'],
            [CustomPropertyResource::class, 'index', 'Custom Client Fields'],
            [CurrencyResource::class, 'index', 'Currencies'],
            [TaxRateResource::class, 'index', 'Tax Rules'],
            [GatewayResource::class, 'index', 'Payment Gateways'],
            [ServerResource::class, 'index', 'Servers'],
            [DomainRegistrarConnectionResource::class, 'index', 'Domain Registrars'],
            [DomainTldResource::class, 'index', 'Domain Pricing'],
            [ServerGroupResource::class, 'index', 'Server Groups'],
            [NotificationTemplateResource::class, 'index', 'Email Templates'],
            [ChatNotificationRuleResource::class, 'index', 'Chat Notifications'],
            [TicketDepartmentResource::class, 'index', 'Ticket Departments'],
            [StaffResource::class, 'index', 'Staff'],
            [RoleResource::class, 'index', 'Staff Roles'],
            [ExtensionResource::class, 'index', 'Extensions'],
            [Extension::class],
            [ApiResource::class, 'index', 'API Credentials'],
            [McpOauthGrantResource::class, 'index', 'MCP Authorizations'],
        ],
    ];

    /**
     * Pages that hide themselves while a setting is off (debugging; immutable invoices): their own
     * shouldRegisterNavigation() decides here too, as in the sidebar (review of #276).
     */
    public const CONDITIONAL = [AdjustmentNoteResource::class];

    /**
     * What the panel registers but needs no line here, and why. Anything else not in MENU (an extension's page) goes to
     * «Addons», so no page is ever left without a way to it (owner 2026-10-21; TopMenuCoverageTest).
     *
     * @var array<class-string, string>
     */
    public const NOT_IN_MENU = [
        Dashboard::class => 'the logo and the sidebar lead to it',
        InvoiceCluster::class => 'a cluster: its pages are under Billing',
        Services::class => 'a cluster: its pages are under Clients',
        Extensions::class => 'a cluster: its pages are under Setup',
    ];

    /**
     * The visible menu for the current staff member; groups with no visible item are dropped.
     *
     * @return list<array{label: string, active: bool, items: list<array{label: string, url: string, active: bool}>}>
     */
    public static function groups(): array
    {
        $groups = [];
        $logsAt = null;
        foreach (self::MENU as $label => $entries) {
            $items = [];
            foreach ($entries as $entry) {
                if ($item = self::item(...$entry)) {
                    $item['active'] = $entry[0] !== Logs::class && self::isCurrent($item['url']);
                    if ($entry[0] === Logs::class) {
                        $logsAt = [count($groups), count($items)];
                    }
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

        // An extension's page (or one added without a line above): under «Addons», as in WHMCS.
        $addons = [];
        foreach (self::unlisted() as $class) {
            // A page its extension keeps out of the menus (a wizard, one reached from a record) stays out of this one.
            if (!$class::shouldRegisterNavigation()) {
                continue;
            }
            if ($item = self::item($class)) {
                $item['active'] = self::isCurrent($item['url']);
                $addons[] = $item;
            }
        }
        if ($addons !== []) {
            $groups[] = ['label' => 'Addons', 'active' => collect($addons)->contains('active', true), 'items' => $addons];
        }

        // «Logs» is current on any log, unless the log has its own line too (Gateway Log under Billing): one group lit.
        if ($logsAt !== null && !collect($groups)->contains('active', true) && Logs::isCurrent()) {
            $groups[$logsAt[0]]['active'] = $groups[$logsAt[0]]['items'][$logsAt[1]]['active'] = true;
        }

        return $groups;
    }

    /** @return list<class-string> the panel's resources and pages with neither a line in MENU nor a reason in NOT_IN_MENU */
    public static function unlisted(): array
    {
        $listed = collect(self::MENU)->flatten(1)->map(fn (array $entry) => $entry[0])->merge(array_keys(self::NOT_IN_MENU))
            // A log is reached through «Logs»: listed there, or it has no way to it (TopMenuCoverageTest).
            ->merge(Logs::classes())->unique()->all();
        $panel = Filament::getPanel('admin');

        return array_values(array_filter([...$panel->getResources(), ...$panel->getPages()], fn (string $class) => !in_array($class, $listed, true)));
    }

    /**
     * An item with a query (a list's tab) is current on that query only; one without, on its page and those under it,
     * unless the page is open on another item's tab (review R6: «Pending Orders», not «List All Orders»).
     */
    private static function isCurrent(string $url): bool
    {
        $current = request()->url();
        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query !== '') {
            parse_str($query, $wanted);

            return $current === strtok($url, '?') && array_intersect_assoc(array_map('strval', $wanted), array_map(fn ($v) => is_scalar($v) ? (string) $v : '', request()->query())) === array_map('strval', $wanted);
        }
        if ($current === $url && request()->query('tab') !== null) {
            return false;
        }

        return $current === $url || Str::startsWith($current, $url . '/');
    }

    /**
     * @param  class-string  $class
     * @param  array<string, string>  $query  e.g. a list's tab
     * @return array{label: string, url: string}|null
     */
    private static function item(string $class, string $page = 'index', ?string $label = null, array $query = []): ?array
    {
        if ($class === Logs::class) {
            $first = Logs::first();

            return $first ? ['label' => $label ?? 'Logs', 'url' => (string) $first->getUrl()] : null;
        }
        if (in_array($class, self::CONDITIONAL, true) && !$class::shouldRegisterNavigation()) {
            return null;
        }
        try {
            if (is_subclass_of($class, Resource::class)) {
                $allowed = $page === 'create' ? $class::canCreate() : $class::canAccess();
                if (!$allowed || !$class::hasPage($page)) {
                    return null;
                }

                return ['label' => $label ?? $class::getNavigationLabel(), 'url' => $class::getUrl($page, $query)];
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
