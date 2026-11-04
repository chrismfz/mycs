<?php

namespace App\Admin\Widgets;

use App\Admin\Pages\CronStats;
use App\Admin\Resources\CpanelAccountResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\ModuleActionResource;
use App\Admin\Resources\PaymentGatewayEventResource;
use App\Admin\Resources\TicketResource;
use App\Models\ChargeAttempt;
use App\Models\CpanelAccount;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\PaymentGatewayEvent;
use App\Models\Ticket;
use App\Services\Cpanel\AccountCheck;
use App\Services\Ops\Health;
use App\Support\AdminBadge;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * «Needs attention» (owner 2026-11-04, direction B2): only what waits on staff, the most urgent first, each row the
 * count of a list and a link to that list with the same filter (the same scope counts both). A row only for a list the
 * viewer may open. The hosting accounts: an extra not billed and a temporary extra to take back (owner 2026-11-04); the
 * accounts not mapped are «Records to check» below.
 */
class NeedsAttention extends Widget
{
    // At the top of the right column of the dashboard (`Dashboard::RIGHT`), above Servers.
    protected static ?int $sort = 4;

    protected string $view = 'admin.widgets.needs-attention';

    /** Red first, then amber, then grey; in each, the order they are built in. */
    private const ORDER = ['danger' => 0, 'warning' => 1, 'gray' => 2];

    /** Automation checks with a row of their own here, left out of the automation row only when that row was checked. */
    private const OWN_ROW = ['module_actions' => ModuleActionResource::class];

    /**
     * @return array{rows: list<array{title: string, detail: string, since: ?Carbon, color: string, url: string}>, checked: list<string>}
     */
    public static function report(): array
    {
        $rows = [];
        $checked = [];

        if (InvoiceResource::canViewAny()) {
            $checked[] = __('Charges');
            // Invoices, not attempts: one invoice charged twice and not recorded is one to settle (its page settles both).
            $charges = ChargeAttempt::query()->needsStaff();
            $count = (clone $charges)->distinct()->count('invoice_id');
            if ($count > 0) {
                $rows[] = self::row(trans_choice(':count invoice with a charge to settle|:count invoices with a charge to settle', $count),
                    __('taken or maybe taken, no payment recorded'), $charges->min('sent_at'), 'danger',
                    InvoiceResource::getUrl('index', ['filters' => ['charge_to_settle' => ['isActive' => true]]]));
            }
        }

        if (ModuleActionResource::canViewAny()) {
            $checked[] = __('Server actions');
            $actions = ModuleAction::query()->needsStaff();
            $count = (clone $actions)->count();
            if ($count > 0) {
                $rows[] = self::row(trans_choice(':count server action needs staff|:count server actions need staff', $count),
                    __('given up, or a domain renewal'), $actions->min('created_at'), 'danger',
                    ModuleActionResource::getUrl('index', ['filters' => ['needs_staff' => ['isActive' => true]]]));
            }
        }

        if (InvoiceResource::canViewAny()) {
            $checked[] = __('Invoices');
            // The sidebar's count of the same (AdminSidebar::QUEUES): one cache for both.
            $count = AdminBadge::count(Invoice::class . '.overdue', fn () => Invoice::query()->overdue()->count());
            if ($count > 0) {
                $rows[] = self::row(trans_choice(':count overdue invoice|:count overdue invoices', $count), '',
                    Invoice::query()->overdue()->min('due_at'), 'danger',
                    InvoiceResource::getUrl('index', ['filters' => ['overdue' => ['isActive' => true]]]));
            }
        }

        if (TicketResource::canViewAny()) {
            $checked[] = __('Tickets');
            // The menu's badge (TicketResource::getNavigationBadge) and the list's «Awaiting us» tab.
            $count = AdminBadge::count('tickets.awaiting', fn () => Ticket::query()->whereIn('status', Ticket::AWAITING_STAFF)->count());
            if ($count > 0) {
                $rows[] = self::row(trans_choice(':count ticket awaiting reply|:count tickets awaiting reply', $count), '', null, 'warning',
                    TicketResource::getUrl('index', ['tab' => 'open']));
            }
        }

        if (CpanelAccountResource::canViewAny()) {
            $checked[] = __('Hosting accounts');
            // The list's own scope (accounts of a server, still on it) and its «Check» filter.
            $accounts = fn (string $check) => CpanelAccount::query()->whereHas('server')->whereNull('gone_at')->where('limits_check', $check);
            $count = $accounts(AccountCheck::EXTRA_NOT_BILLED)->count();
            if ($count > 0) {
                $rows[] = self::row(trans_choice(':count hosting account with an extra not billed|:count hosting accounts with an extra not billed', $count),
                    __('more disk than the package and what we bill'), null, 'warning',
                    CpanelAccountResource::getUrl('index', ['filters' => ['limits_check' => ['value' => AccountCheck::EXTRA_NOT_BILLED]]]));
            }
            $back = $accounts(AccountCheck::EXTRA_TO_TAKE_BACK);
            $count = (clone $back)->count();
            if ($count > 0) {
                $rows[] = self::row(trans_choice(':count temporary extra to take back|:count temporary extras to take back', $count),
                    __('its date has passed'), $back->min('temporary_extra_until'), 'warning',
                    CpanelAccountResource::getUrl('index', ['filters' => ['limits_check' => ['value' => AccountCheck::EXTRA_TO_TAKE_BACK]]]));
            }
        }

        if (CronStats::canAccess()) {
            $checked[] = __('Automation');
            try {
                // A role that may open Automation status but not Module actions still sees given-up actions here (review of #631).
                $failing = array_filter(Health::cached()['checks'], fn (array $check, string $name): bool => $check['status'] !== Health::OK
                    && !(isset(self::OWN_ROW[$name]) && self::OWN_ROW[$name]::canViewAny()), ARRAY_FILTER_USE_BOTH);
            } catch (Throwable) {
                $failing = []; // Automation status says why; the dashboard still opens.
            }
            if ($failing !== []) {
                $failed = in_array(Health::FAIL, array_column($failing, 'status'), true);
                $rows[] = self::row(trans_choice(':count automation check not ok|:count automation checks not ok', count($failing)),
                    implode(', ', array_map(fn (string $name): string => __('health.' . $name), array_keys($failing))), null,
                    $failed ? 'danger' : 'warning', CronStats::getUrl());
            }
        }

        if (PaymentGatewayEventResource::canViewAny()) {
            $checked[] = __('Gateway events');
            // Nobody marks one checked: the last days only, as the list's «Last days» filter.
            $foreign = PaymentGatewayEvent::query()->where('outcome', PaymentGatewayEvent::OUTCOME_FOREIGN)
                ->where('created_at', '>=', now()->subDays(PaymentGatewayEventResource::RECENT_DAYS));
            $count = (clone $foreign)->count();
            if ($count > 0) {
                $rows[] = self::row(trans_choice(':count gateway event from another company|:count gateway events from another company', $count),
                    __('Last :days days', ['days' => PaymentGatewayEventResource::RECENT_DAYS]), $foreign->min('created_at'), 'gray',
                    PaymentGatewayEventResource::getUrl('index', ['filters' => ['outcome' => ['value' => PaymentGatewayEvent::OUTCOME_FOREIGN], 'recent' => ['isActive' => true]]]));
            }
        }

        // usort keeps the build order within a colour (PHP 8's sort is stable).
        usort($rows, fn (array $a, array $b): int => self::ORDER[$a['color']] <=> self::ORDER[$b['color']]);

        return ['rows' => $rows, 'checked' => $checked];
    }

    /** @return array{title: string, detail: string, since: ?Carbon, color: string, url: string} */
    private static function row(string $title, string $detail, mixed $since, string $color, string $url): array
    {
        return ['title' => $title, 'detail' => $detail, 'since' => $since === null ? null : Carbon::parse($since), 'color' => $color, 'url' => $url];
    }

    public function render(): View
    {
        return view($this->view, self::report());
    }

    public static function canView(): bool
    {
        return InvoiceResource::canViewAny() || ModuleActionResource::canViewAny() || TicketResource::canViewAny()
            || CpanelAccountResource::canViewAny() || CronStats::canAccess() || PaymentGatewayEventResource::canViewAny();
    }
}
