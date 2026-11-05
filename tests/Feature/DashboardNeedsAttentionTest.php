<?php

namespace Tests\Feature;

use App\Admin\Pages\Dashboard;
use App\Admin\Resources\CpanelAccountResource\Pages\ListCpanelAccounts;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Admin\Resources\ModuleActionResource\Pages\ListModuleActions;
use App\Admin\Resources\PaymentGatewayEventResource\Pages\ListPaymentGatewayEvents;
use App\Admin\Widgets\NeedsAttention;
use App\Admin\Widgets\Servers;
use App\Enums\InvoiceTransactionStatus;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\PaymentGatewayEvent;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cpanel\AccountCheck;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dashboard's «Needs attention» (2026-11-04): what waits on staff, the most urgent first, each row a list's count and
 * a link to that list with the same filter. The lists are read here through their own filters, never a copy of them.
 */
class DashboardNeedsAttentionTest extends TestCase
{
    use RefreshDatabase;

    private Gateway $gateway;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // A frozen clock: the rows are made from «today» and checked against it again, and a run across midnight (UTC) saw
        // two different days (overdue invoices, charges to settle, hosting accounts; 2026-11-05).
        $this->freezeTime();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['app.admin_badge_cache_seconds' => 0]);
        // No automation check of its own here: the report a minute old, all ok (Health::cached()).
        Cache::put('ops.health', ['at' => now()->getTimestamp(), 'report' => ['status' => 'ok', 'checks' => ['database' => ['status' => 'ok', 'detail' => '']]]]);
        $this->gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->user = User::factory()->create();
    }

    private function actAs(array $permissions): void
    {
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]), 'staff');
    }

    private function invoice(array $attributes = []): Invoice
    {
        $invoice = Invoice::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 50]);

        return $invoice;
    }

    private function charge(Invoice $invoice, string $status, \DateTimeInterface $sent): ChargeAttempt
    {
        $agreement = BillingAgreement::query()->create(['client_id' => $invoice->client_id, 'gateway_id' => $this->gateway->id, 'name' => 'Card',
            'external_reference' => 'ref-' . Str::random(6), 'type' => 'card']);

        return ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'client_id' => $invoice->client_id,
            'billing_agreement_id' => $agreement->id, 'amount' => 50, 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => $status, 'started_at' => $sent, 'sent_at' => $sent]);
    }

    private function action(array $attributes): ModuleAction
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 1]);

        return ModuleAction::query()->create($attributes + ['service_id' => $service->id, 'action' => 'suspend', 'status' => ModuleAction::STATUS_OPEN]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return NeedsAttention::report()['rows'];
    }

    /** The query string of a row's link, as the list reads it. */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_nothing_waiting_says_so_and_what_was_checked(): void
    {
        $this->actAs(['*']);

        $this->assertSame([], $this->rows());
        Livewire::test(NeedsAttention::class)
            ->assertSee(__('Nothing needs attention.'))
            ->assertSee(__('Checked just now: :what.', ['what' => mb_strtolower(implode(', ', [__('Charges'), __('Server actions'), __('Invoices'), __('Tickets'), __('Hosting accounts'), __('Automation'), __('Gateway events')]))]));
    }

    /** Unknown, started with no answer past HOLD_MINUTES, taken an hour ago and not recorded: per invoice, not per attempt. */
    public function test_charges_to_settle_count_invoices_and_the_filter_lists_the_same(): void
    {
        $this->actAs(['*']);
        $unknown = $this->invoice();
        $this->charge($unknown, ChargeAttempt::STATUS_UNKNOWN, now()->subDays(2));
        $this->charge($unknown, ChargeAttempt::STATUS_UNKNOWN, now()->subDay());
        $stale = $this->invoice();
        $this->charge($stale, ChargeAttempt::STATUS_STARTED, now()->subMinutes(ChargeAttempt::HOLD_MINUTES + 1));
        $taken = $this->invoice();
        $this->charge($taken, ChargeAttempt::STATUS_SUCCEEDED, now()->subHours(2));
        // Not staff's (yet): on its way, taken a minute ago, failed, and taken with its payment recorded.
        $this->charge($this->invoice(), ChargeAttempt::STATUS_STARTED, now()->subMinute());
        $this->charge($this->invoice(), ChargeAttempt::STATUS_SUCCEEDED, now()->subMinute());
        $this->charge($this->invoice(), ChargeAttempt::STATUS_FAILED, now()->subDays(3));
        $recorded = $this->invoice();
        $this->charge($recorded, ChargeAttempt::STATUS_SUCCEEDED, now()->subHours(3));
        $recorded->transactions()->create(['gateway_id' => $this->gateway->id, 'amount' => 50, 'status' => InvoiceTransactionStatus::Succeeded,
            'is_credit_transaction' => false, 'transaction_id' => 'tx-1']);

        $row = $this->rows()[0];

        $this->assertSame(trans_choice(':count invoice with a charge to settle|:count invoices with a charge to settle', 3), $row['title']);
        $this->assertSame('danger', $row['color']);
        $this->assertTrue($row['since']->isSameDay(now()->subDays(2)), 'the oldest attempt');
        $this->assertSame(['filters' => ['charge_to_settle' => ['isActive' => '1']]], $this->queryOf($row['url']));
        Livewire::test(ListInvoices::class)->filterTable('charge_to_settle')
            ->assertCanSeeTableRecords([$unknown, $stale, $taken])->assertCountTableRecords(3);
    }

    /** Given up after its retries, or a domain's action; one still retrying is not staff's. */
    public function test_server_actions_needing_staff_and_the_filter_lists_the_same(): void
    {
        $this->actAs(['*']);
        $givenUp = $this->action(['given_up_at' => now()->subHours(5), 'last_error' => 'refused']);
        $domain = Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'renew', 'fqdn' => 'renew.test']);
        $renewal = ModuleAction::query()->create(['domain_id' => $domain->id, 'action' => ModuleAction::DOMAIN_RENEW, 'status' => ModuleAction::STATUS_OPEN]);
        $this->action([]); // still retrying
        $this->action(['given_up_at' => now(), 'status' => ModuleAction::STATUS_RESOLVED]);

        $row = $this->rows()[0];

        $this->assertSame(trans_choice(':count server action needs staff|:count server actions need staff', 2), $row['title'], 'given up, and a paid domain renewal (never given up by itself)');
        $this->assertSame(['filters' => ['needs_staff' => ['isActive' => '1']]], $this->queryOf($row['url']));
        Livewire::test(ListModuleActions::class)->filterTable('needs_staff')->assertCanSeeTableRecords([$givenUp, $renewal])->assertCountTableRecords(2);
    }

    public function test_overdue_invoices_link_to_the_overdue_filter(): void
    {
        $this->actAs(['*']);
        $this->invoice(['due_at' => now()->subDays(17)]);
        $this->invoice(['due_at' => now()->subDays(3)]);
        $this->invoice(['due_at' => now()->addDays(3)]);

        $row = $this->rows()[0];

        $this->assertSame(trans_choice(':count overdue invoice|:count overdue invoices', 2), $row['title']);
        $this->assertTrue($row['since']->isSameDay(now()->subDays(17)));
        $this->assertSame(['filters' => ['overdue' => ['isActive' => '1']]], $this->queryOf($row['url']));
    }

    /** A foreign event of the last days, never one older (nobody marks one checked); the list's filter is the same window. */
    public function test_gateway_events_from_another_company_of_the_last_days(): void
    {
        $this->actAs(['*']);
        $event = fn (string $outcome, $at) => tap(PaymentGatewayEvent::query()->create(['gateway' => 'paypal', 'endpoint' => 'webhook', 'method' => 'POST', 'outcome' => $outcome]),
            fn (PaymentGatewayEvent $row) => $row->forceFill(['created_at' => $at])->save());
        $recent = $event(PaymentGatewayEvent::OUTCOME_FOREIGN, now()->subDay());
        $event(PaymentGatewayEvent::OUTCOME_FOREIGN, now()->subDays(8));
        $event(PaymentGatewayEvent::OUTCOME_RECORDED, now());

        $row = $this->rows()[0];

        $this->assertSame(trans_choice(':count gateway event from another company|:count gateway events from another company', 1), $row['title']);
        $this->assertSame('gray', $row['color']);
        $this->assertSame(['filters' => ['outcome' => ['value' => 'foreign'], 'recent' => ['isActive' => '1']]], $this->queryOf($row['url']));
        Livewire::test(ListPaymentGatewayEvents::class)->filterTable('outcome', 'foreign')->filterTable('recent')
            ->assertCanSeeTableRecords([$recent])->assertCountTableRecords(1);
    }

    /** Red first, amber, then grey; an automation check that is its own row (module actions) is not counted twice. */
    public function test_the_most_urgent_first_and_automation_checks_not_twice(): void
    {
        $this->actAs(['*']);
        Cache::put('ops.health', ['at' => now()->getTimestamp(), 'report' => ['status' => 'warn', 'checks' => [
            'mail' => ['status' => 'warn', 'detail' => 'stuck'], 'module_actions' => ['status' => 'warn', 'detail' => '1 given up'], 'database' => ['status' => 'ok', 'detail' => ''],
        ]]]);
        $event = PaymentGatewayEvent::query()->create(['gateway' => 'paypal', 'endpoint' => 'webhook', 'method' => 'POST', 'outcome' => PaymentGatewayEvent::OUTCOME_FOREIGN]);
        $this->invoice(['due_at' => now()->subDays(2)]);
        $this->action(['given_up_at' => now()]);

        $rows = $this->rows();

        $this->assertSame(['danger', 'danger', 'warning', 'gray'], array_column($rows, 'color'));
        $this->assertSame(trans_choice(':count automation check not ok|:count automation checks not ok', 1), $rows[2]['title']);
        $this->assertSame(__('health.mail'), $rows[2]['detail']);
        $this->assertNotNull($event);
    }

    /** A row only for a list the viewer may open: an invoices-only role sees no server actions, and links nowhere it may not go. */
    public function test_rows_only_for_lists_the_viewer_may_open(): void
    {
        $this->actAs(['admin.invoices.viewAny']);
        $this->invoice(['due_at' => now()->subDays(2)]);
        $this->action(['given_up_at' => now()]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertStringStartsWith(InvoiceResource::getUrl('index'), $rows[0]['url']);
        $this->assertSame([__('Charges'), __('Invoices')], NeedsAttention::report()['checked']);
    }

    /** Hosting accounts (owner 2026-11-04): an extra not billed, and a temporary extra whose date passed; never an approved one, nor one gone. */
    public function test_hosting_accounts_with_an_extra_not_billed_or_one_to_take_back_and_the_filter_lists_the_same(): void
    {
        $this->actAs(['admin.servers.viewAny']);
        $server = Server::query()->create(['name' => 'Rigel', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $account = fn (string $user, ?string $check, array $more = []) => CpanelAccount::query()->create($more + ['server_id' => $server->id, 'user' => $user,
            'domain' => $user . '.test', 'limits_check' => $check, 'last_seen_at' => now()]);
        $extra = $account('extra1', AccountCheck::EXTRA_NOT_BILLED);
        $account('extra2', AccountCheck::EXTRA_NOT_BILLED);
        $back = $account('back1', AccountCheck::EXTRA_TO_TAKE_BACK, ['temporary_extra_mb' => 10240, 'temporary_extra_until' => today()->subDays(4)]);
        $account('gone', AccountCheck::EXTRA_NOT_BILLED, ['gone_at' => now()]);
        $account('approved', AccountCheck::APPROVED);
        $account('temporary', AccountCheck::TEMPORARY_EXTRA);

        $rows = $this->rows();

        $this->assertSame([trans_choice(':count hosting account with an extra not billed|:count hosting accounts with an extra not billed', 2),
            trans_choice(':count temporary extra to take back|:count temporary extras to take back', 1)], array_column($rows, 'title'));
        $this->assertSame(['warning', 'warning'], array_column($rows, 'color'));
        $this->assertTrue($rows[1]['since']->isSameDay(today()->subDays(3)), 'the day after the oldest last day: when it ended');
        $this->assertSame(['filters' => ['limits_check' => ['value' => AccountCheck::EXTRA_NOT_BILLED]]], $this->queryOf($rows[0]['url']));
        $this->assertSame([__('Hosting accounts')], NeedsAttention::report()['checked']);
        Livewire::test(ListCpanelAccounts::class)->filterTable('limits_check', AccountCheck::EXTRA_NOT_BILLED)->assertCountTableRecords(2)->assertCanSeeTableRecords([$extra]);
        Livewire::test(ListCpanelAccounts::class)->filterTable('limits_check', AccountCheck::EXTRA_TO_TAKE_BACK)->assertCountTableRecords(1)->assertCanSeeTableRecords([$back]);
    }

    public function test_tickets_awaiting_reply_link_to_the_awaiting_tab(): void
    {
        $this->actAs(['*']);
        Ticket::factory()->create(['client_id' => $this->user->client()->id, 'subject' => 'Help', 'status' => Ticket::AWAITING_STAFF[0]]);
        Ticket::factory()->create(['client_id' => $this->user->client()->id, 'subject' => 'Done', 'status' => Ticket::STATUS_CLOSED]);

        $row = $this->rows()[0];

        $this->assertSame(trans_choice(':count ticket awaiting reply|:count tickets awaiting reply', 1), $row['title']);
        $this->assertSame(['warning', ['tab' => 'open']], [$row['color'], $this->queryOf($row['url'])]);
    }

    /** A failed check is red; module actions stay in the automation row for a role that may not open their list. */
    public function test_a_failed_check_is_red_and_module_actions_stay_for_a_role_without_their_list(): void
    {
        $this->actAs(['admin.cron_stats.view']);
        Cache::put('ops.health', ['at' => now()->getTimestamp(), 'report' => ['status' => 'fail', 'checks' => [
            'tax' => ['status' => 'fail', 'detail' => 'no country'], 'module_actions' => ['status' => 'warn', 'detail' => '1 given up'],
        ]]]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('danger', $rows[0]['color']);
        $this->assertSame(__('health.tax') . ', ' . __('health.module_actions'), $rows[0]['detail']);
        $this->assertNotSame('health.tax', __('health.tax'));
    }

    public function test_staff_with_none_of_its_lists_do_not_see_it(): void
    {
        $this->actAs(['admin.widgets.support']);

        $this->assertFalse(NeedsAttention::canView());
        $this->assertNotContains(NeedsAttention::class, (new Dashboard)->layout()['right']);
    }

    public function test_it_tops_the_right_column_and_counts_again_every_minute(): void
    {
        $this->actAs(['*']);
        $this->invoice(['due_at' => now()->subDays(2)]);

        $this->assertSame([NeedsAttention::class, Servers::class], array_slice((new Dashboard)->layout()['right'], 0, 2));
        Livewire::test(NeedsAttention::class)->assertSeeHtml('wire:poll.60s')
            ->assertSee(trans_choice(':count overdue invoice|:count overdue invoices', 1));
    }
}
