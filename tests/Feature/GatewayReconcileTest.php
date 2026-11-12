<?php

namespace Tests\Feature;

use App\Admin\Resources\GatewayTransactionResource;
use App\Admin\Resources\GatewayTransactionResource\Pages\ListGatewayTransactions;
use App\Admin\Widgets\NeedsAttention;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\GatewayTransaction;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Money\MoneyCheck;
use App\Services\Money\Reconcile\GatewayReconcile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * The daily comparison of PayPal's own list of transactions with what is recorded here (gateways:reconcile, owner
 * 2026-11-12; docs/payments-tracking.md «Συμφωνία gateway»). PayPal is faked: no test calls it.
 */
class GatewayReconcileTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-m.sandbox.paypal.com';

    private User $user;

    private Gateway $gateway;

    /** @var list<list<array<string, mixed>>> the pages PayPal answers, each a list of transaction_info */
    private array $pages = [];

    /** @var list<array<string, mixed>> the query of every search made */
    private array $searches = [];

    /** @var list<\Closure> answers given instead of the pages, first come first */
    private array $instead = [];

    private ?string $refreshed = null;

    private int $tokens = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00'));
        $this->user = User::factory()->create();
        $this->gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $this->gateway->update(['enabled' => true]);
        $this->configure(['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh', 'reconcile_from' => '2026-11-14 22:00']);

        Http::fake([
            self::API . '/v1/oauth2/token' => function () {
                $this->tokens++;

                return Http::response(['access_token' => 'token-' . $this->tokens]);
            },
            self::API . '/v1/reporting/transactions*' => function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->searches[] = $query + ['_method' => $request->method(), '_token' => $request->header('Authorization')[0] ?? ''];
                if ($this->instead !== []) {
                    return array_shift($this->instead)();
                }
                $page = (int) ($query['page'] ?? 1);

                return Http::response([
                    'transaction_details' => array_map(fn (array $info) => ['transaction_info' => $info], $this->pages[$page - 1] ?? []),
                    'page' => $page, 'total_pages' => max(1, count($this->pages)),
                    'last_refreshed_datetime' => $this->refreshed ?? now()->subHours(3)->format('Y-m-d\TH:i:sO'),
                ]);
            },
        ]);
    }

    /** @param  array<string, string>  $settings */
    private function configure(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    /**
     * One row of Transaction Search.
     *
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function row(string $id, string $value, string $at, string $code = 'T0006', string $currency = 'EUR', array $more = []): array
    {
        return $more + [
            'transaction_id' => $id, 'transaction_event_code' => $code, 'transaction_status' => 'S',
            'transaction_initiation_date' => Carbon::parse($at)->format('Y-m-d\TH:i:sO'),
            'transaction_amount' => ['currency_code' => $currency, 'value' => $value],
        ];
    }

    private function recorded(string $id, float $amount, string $at, string $currency = 'EUR'): InvoiceTransaction
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => $currency]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $amount]);
        $payment = ExtensionHelper::addPayment($invoice->id, 'PayPal', $amount, null, $id, currency: $currency);
        DB::table('invoice_transactions')->where('id', $payment->id)->update(['created_at' => $at]);

        return $payment->fresh();
    }

    private function reconcile(string $options = ''): int
    {
        return Artisan::call(trim('gateways:reconcile ' . $options));
    }

    private function line(string $reference, string $what = 'payment'): ?GatewayTransaction
    {
        return GatewayTransaction::query()->where('gateway', 'PayPal')->where('what', $what)->where('reference', $reference)->first();
    }

    /** @return array<string, int> what must never move: the comparison writes its own table only */
    private function money(): array
    {
        return ['payments' => DB::table('invoice_transactions')->count(), 'journal' => DB::table('money_events')->count(),
            'refunds' => DB::table('gateway_refunds')->count(), 'credits' => DB::table('credits')->count(),
            'paid' => DB::table('invoices')->where('status', 'paid')->count()];
    }

    public function test_every_page_is_read_and_each_transaction_is_matched_by_its_id(): void
    {
        $here = $this->recorded('PAY-MATCH', 12.50, '2026-11-18 09:00:05');
        $this->pages = [
            [$this->row('PAY-MATCH', '12.50', '2026-11-18 09:00:00')],
            // An old-style subscription's payment: nothing here knows it, and PayPal names the subscription.
            [$this->row('PAY-LEGACY', '9.90', '2026-11-19 03:00:00', 'T0002', 'EUR', ['paypal_reference_id' => 'I-SUBSCR1PT10N', 'paypal_reference_id_type' => 'SUB'])],
        ];
        $before = $this->money();

        $this->assertSame(0, $this->reconcile());

        $this->assertSame(['1', '2'], array_column($this->searches, 'page'));
        foreach ($this->searches as $search) {
            $this->assertSame('GET', $search['_method']);
            $this->assertSame('transaction_info', $search['fields'], 'ids, dates and amounts only: never the payer');
        }
        $matched = $this->line('PAY-MATCH');
        $this->assertSame([GatewayTransaction::MATCHED, GatewayTransaction::OK, $here->invoice_id, '12.50'],
            [$matched->kind, $matched->status, $matched->invoice_id, $matched->recorded_amount]);
        $legacy = $this->line('PAY-LEGACY');
        $this->assertSame([GatewayTransaction::NOT_RECORDED, GatewayTransaction::OPEN, 'I-SUBSCR1PT10N', '9.90', 'EUR'],
            [$legacy->kind, $legacy->status, $legacy->agreement, $legacy->amount, $legacy->currency_code]);
        $this->assertStringContainsString('I-SUBSCR1PT10N', $legacy->describe());
        $this->assertSame($before, $this->money(), 'nothing is recorded, credited or refunded');
    }

    public function test_what_the_money_check_says_and_repeats_until_staff_mark_it_known(): void
    {
        $this->pages = [[$this->row('PAY-UNKNOWN', '20.00', '2026-11-18 10:00:00')]];
        $this->reconcile();

        $findings = (new MoneyCheck)->run(['gateway_unreconciled']);
        $this->assertSame([['rule' => 'gateway_unreconciled', 'subject' => 'PayPal payment PAY-UNKNOWN',
            'detail' => 'at PayPal on 2026-11-18 for 20.00 EUR, not recorded here']], $findings);

        $this->travel(1)->days();
        $this->reconcile();
        $this->assertSame($findings, (new MoneyCheck)->run(['gateway_unreconciled']), 'the same words the next day: mailed once, then weekly');
        $this->artisan('mycs:money-check --rule=gateway_unreconciled')->assertExitCode(1);

        $this->line('PAY-UNKNOWN')->update(['status' => GatewayTransaction::ACKNOWLEDGED, 'note' => 'a friend paid the owner']);
        $this->reconcile();
        $this->assertSame(GatewayTransaction::ACKNOWLEDGED, $this->line('PAY-UNKNOWN')->status, 'a known one stays known');
        $this->assertSame([], (new MoneyCheck)->run(['gateway_unreconciled']));
    }

    public function test_the_reporting_delay_is_not_a_difference(): void
    {
        $this->refreshed = '2026-11-20T09:00:00+0000'; // PayPal's list is complete up to three hours ago
        $this->recorded('PAY-JUST-NOW', 5.00, '2026-11-20 11:00:00'); // after that: PayPal cannot list it yet
        $this->recorded('PAY-AT-THE-EDGE', 5.00, '2026-11-20 08:50:00'); // within the margin
        $this->recorded('PAY-NOWHERE', 7.00, '2026-11-17 10:00:00');
        $this->recorded('PAY-BEFORE', 8.00, '2026-11-14 22:00:03'); // taken just before «from», recorded just after
        $this->pages = [[
            $this->row('PAY-BEFORE', '8.00', '2026-11-14 21:59:58'),
            // Taken twenty minutes ago: its webhook may still be on its way.
            $this->row('PAY-FRESH', '6.00', '2026-11-20 11:40:00'),
            // Listed as pending: found, never judged.
            $this->row('PAY-PENDING', '4.00', '2026-11-18 10:00:00', 'T0006', 'EUR', ['transaction_status' => 'P']),
        ]];

        $this->reconcile();

        $this->assertSame(['PAY-NOWHERE'], GatewayTransaction::query()->pluck('reference')->all());
        $missing = $this->line('PAY-NOWHERE');
        $this->assertSame([GatewayTransaction::NOT_AT_GATEWAY, GatewayTransaction::OPEN, '7.00'], [$missing->kind, $missing->status, $missing->amount]);
        $this->assertStringContainsString("not in PayPal's list", $missing->describe());

        // The next day PayPal has caught up with one, and still does not list the other.
        $this->travel(1)->days();
        $this->refreshed = '2026-11-21T09:00:00+0000';
        $this->pages = [[$this->row('PAY-JUST-NOW', '5.00', '2026-11-20 10:59:50'), $this->row('PAY-FRESH', '6.00', '2026-11-20 11:40:00')]];
        $this->reconcile();
        $this->assertSame(GatewayTransaction::MATCHED, $this->line('PAY-JUST-NOW')->kind);
        $this->assertSame(GatewayTransaction::NOT_RECORDED, $this->line('PAY-FRESH')->kind);
        $this->assertSame(GatewayTransaction::NOT_AT_GATEWAY, $this->line('PAY-AT-THE-EDGE')->kind);
    }

    public function test_a_refund_and_a_reversal(): void
    {
        $payment = $this->recorded('PAY-R', 30.00, '2026-11-16 10:00:00');
        $disputed = $this->recorded('PAY-D', 40.00, '2026-11-16 11:00:00');
        GatewayRefund::query()->create(['invoice_transaction_id' => $payment->id, 'gateway' => 'PayPal', 'reference' => 'REF-KNOWN', 'payment_reference' => 'PAY-R',
            'amount' => 10, 'currency_code' => 'EUR', 'recorded_amount' => 10, 'origin' => GatewayRefund::ORIGIN_GATEWAY]);
        // A row that recorded nothing (nothing was left on its payment): staff's, as its webhook said.
        GatewayRefund::query()->create(['invoice_transaction_id' => $payment->id, 'gateway' => 'PayPal', 'reference' => 'REF-NOTHING', 'payment_reference' => 'PAY-R',
            'amount' => 3, 'currency_code' => 'EUR', 'recorded_amount' => 0, 'origin' => GatewayRefund::ORIGIN_GATEWAY]);
        // A chargeback is recorded under its dispute's id, not the reversal's.
        GatewayRefund::query()->create(['invoice_transaction_id' => $disputed->id, 'gateway' => 'PayPal', 'reference' => 'PP-D-123', 'payment_reference' => 'PAY-D',
            'amount' => 40, 'currency_code' => 'EUR', 'recorded_amount' => 40, 'origin' => GatewayRefund::ORIGIN_CHARGEBACK]);
        $of = fn (string $payment) => ['paypal_reference_id' => $payment, 'paypal_reference_id_type' => 'TXN'];
        $this->pages = [[
            $this->row('PAY-R', '30.00', '2026-11-16 10:00:00'), $this->row('PAY-D', '40.00', '2026-11-16 11:00:00'),
            $this->row('REF-KNOWN', '-10.00', '2026-11-17 10:00:00', 'T1107', 'EUR', $of('PAY-R')),
            $this->row('REF-NOTHING', '-3.00', '2026-11-17 10:30:00', 'T1107', 'EUR', $of('PAY-R')),
            $this->row('REF-BY-HAND-AT-PAYPAL', '-5.00', '2026-11-17 11:00:00', 'T1107', 'EUR', $of('PAY-R')),
            $this->row('REV-KNOWN', '-40.00', '2026-11-18 11:00:00', 'T1201', 'EUR', $of('PAY-D')),
            $this->row('REV-UNKNOWN', '-30.00', '2026-11-18 12:00:00', 'T1106', 'EUR', $of('PAY-R')),
            // Not ours to compare: a fee, a withdrawal to the bank, a payment we sent.
            $this->row('FEE-1', '-0.35', '2026-11-18 12:00:00', 'T0113'), $this->row('WITHDRAW-1', '-500.00', '2026-11-18 12:00:00', 'T0400'),
            $this->row('SENT-1', '-15.00', '2026-11-18 12:00:00', 'T0000'),
        ]];

        $this->reconcile();

        $this->assertSame([GatewayTransaction::MATCHED, $payment->invoice_id], [$this->line('REF-KNOWN', 'refund')->kind, $this->line('REF-KNOWN', 'refund')->invoice_id]);
        $this->assertSame(GatewayTransaction::NOT_RECORDED, $this->line('REF-NOTHING', 'refund')->kind);
        $unknown = $this->line('REF-BY-HAND-AT-PAYPAL', 'refund');
        $this->assertSame([GatewayTransaction::NOT_RECORDED, GatewayTransaction::OPEN, '5.00', 'PAY-R'], [$unknown->kind, $unknown->status, $unknown->amount, $unknown->parent_reference]);
        $this->assertSame('at PayPal on 2026-11-17 for 5.00 EUR, not recorded here (of payment PAY-R)', $unknown->describe());
        $this->assertSame(GatewayTransaction::MATCHED, $this->line('REV-KNOWN', 'reversal')->kind);
        $this->assertSame(GatewayTransaction::NOT_RECORDED, $this->line('REV-UNKNOWN', 'reversal')->kind);
        $this->assertSame(7, GatewayTransaction::query()->count(), 'fees, withdrawals and payments sent are not kept');

        // A refund recorded with another amount than PayPal gave back.
        GatewayRefund::query()->where('reference', 'REF-KNOWN')->update(['amount' => 12]);
        $this->reconcile();
        $refund = $this->line('REF-KNOWN', 'refund');
        $this->assertSame([GatewayTransaction::MISMATCH, GatewayTransaction::OPEN, '10.00', '12.00'], [$refund->kind, $refund->status, $refund->amount, $refund->recorded_amount]);
    }

    public function test_another_amount_or_another_currency(): void
    {
        $usd = $this->recorded('PAY-USD', 10.00, '2026-11-18 09:00:00'); // recorded on a EUR invoice
        $this->recorded('PAY-SHORT', 25.00, '2026-11-18 09:30:00');
        $this->recorded('PAY-SAME', 25.00, '2026-11-18 09:40:00', 'USD');
        $this->pages = [[
            $this->row('PAY-USD', '10.00', '2026-11-18 09:00:00', 'T0006', 'USD'),
            $this->row('PAY-SHORT', '52.00', '2026-11-18 09:30:00'),
            $this->row('PAY-SAME', '25.00', '2026-11-18 09:40:00', 'T0003', 'USD'),
        ]];

        $this->reconcile();

        $currency = $this->line('PAY-USD');
        $this->assertSame([GatewayTransaction::MISMATCH, GatewayTransaction::OPEN, 'USD', 'EUR', $usd->invoice_id],
            [$currency->kind, $currency->status, $currency->currency_code, $currency->recorded_currency, $currency->invoice_id]);
        $this->assertSame("10.00 USD at PayPal on 2026-11-18, recorded here as 10.00 EUR (invoice #{$usd->invoice_id})", $currency->describe());
        $this->assertSame([GatewayTransaction::MISMATCH, '52.00', '25.00'], [$this->line('PAY-SHORT')->kind, $this->line('PAY-SHORT')->amount, $this->line('PAY-SHORT')->recorded_amount]);
        $this->assertSame(GatewayTransaction::MATCHED, $this->line('PAY-SAME')->kind);
    }

    public function test_an_expired_token_is_renewed_and_the_list_still_read(): void
    {
        $this->pages = [[$this->row('PAY-X', '5.00', '2026-11-18 10:00:00')]];
        $this->instead = [fn () => Http::response(['error' => 'invalid_token', 'error_description' => 'Access Token not found in cache'], 401)];

        $this->assertSame(0, $this->reconcile());

        $this->assertSame(2, $this->tokens);
        $this->assertSame(['Bearer token-1', 'Bearer token-2'], array_column($this->searches, '_token'));
        $this->assertNotNull($this->line('PAY-X'));
        $this->assertSame(0, DB::table('email_logs')->where('subject', 'like', '%not read%')->count());
    }

    public function test_a_429_changes_nothing_and_staff_are_told_once(): void
    {
        $this->pages = [[$this->row('PAY-OPEN', '5.00', '2026-11-18 10:00:00')]];
        $this->reconcile();
        $rows = GatewayTransaction::query()->get()->toArray();

        // Refused on the second page: half a list is never compared.
        $this->pages = [[$this->row('PAY-OTHER', '6.00', '2026-11-18 11:00:00')], []];
        $busy = fn () => Http::response(['name' => 'RATE_LIMIT_REACHED', 'message' => 'Too many requests', 'debug_id' => 'abc123'], 429);
        $this->travel(1)->days();
        $this->searches = [];
        $this->instead = [fn () => Http::response(['transaction_details' => [['transaction_info' => $this->row('PAY-OTHER', '6.00', '2026-11-18 11:00:00')]], 'page' => 1, 'total_pages' => 2]), $busy];
        $this->assertSame(1, $this->reconcile());
        $this->assertCount(2, $this->searches);
        $this->assertSame($rows, GatewayTransaction::query()->get()->toArray(), 'nothing compared, nothing closed');
        $this->assertSame(1, DB::table('email_logs')->where('subject', 'PayPal transactions not read')->count());
        $this->assertSame('HTTP 429 RATE_LIMIT_REACHED', GatewayReconcile::state()['PayPal']['error'], 'no debug id: the same words every day');

        // Still refused the next day: not mailed again, and the money check says the list is not read.
        $this->travel(1)->days();
        $this->instead = [$busy];
        $this->assertSame(1, $this->reconcile());
        $this->assertSame(1, DB::table('email_logs')->where('subject', 'PayPal transactions not read')->count());
        $said = collect((new MoneyCheck)->run(['gateway_unreconciled']))->firstWhere('subject', 'PayPal transactions');
        $this->assertSame('its list was not read since 2026-11-20 12:00 (HTTP 429 RATE_LIMIT_REACHED): nothing is compared (php artisan gateways:reconcile)', $said['detail']);

        // Read again: said no more; a later failure is a new one.
        $this->pages = [[]];
        $this->assertSame(0, $this->reconcile());
        $this->assertNull(collect((new MoneyCheck)->run(['gateway_unreconciled']))->firstWhere('subject', 'PayPal transactions'));
        $this->instead = [$busy];
        $this->reconcile();
        $this->assertSame(2, DB::table('email_logs')->where('subject', 'PayPal transactions not read')->count());
    }

    public function test_an_app_without_transaction_search_fails_loudly(): void
    {
        $this->instead = [fn () => Http::response(['name' => 'NOT_AUTHORIZED', 'message' => 'Authorization failed due to insufficient permissions.', 'debug_id' => 'f00'], 403)];

        $this->assertSame(1, $this->reconcile());

        $mail = DB::table('email_logs')->where('subject', 'PayPal transactions not read')->first();
        $this->assertNotNull($mail);
        $this->assertStringContainsString('Transaction Search', (string) $mail->body);
        $this->assertStringNotContainsString('secret', (string) $mail->body);
        $this->assertSame(0, GatewayTransaction::query()->count());
        // Never read at all: the money check says so from the first day.
        $said = collect((new MoneyCheck)->run(['gateway_unreconciled']))->firstWhere('subject', 'PayPal transactions');
        $this->assertSame('its list was not read yet (HTTP 403 NOT_AUTHORIZED): nothing is compared (php artisan gateways:reconcile)', $said['detail']);
    }

    public function test_nothing_before_reconcile_from_is_judged_and_empty_means_off(): void
    {
        // WHMCS's days: a payment it recorded and one it did not, and a payment imported with its id.
        $this->recorded('PAY-WHMCS', 10.00, '2026-11-14 10:00:00');
        $this->pages = [[$this->row('PAY-WHMCS-MISSED', '11.00', '2026-11-14 12:00:00'), $this->row('PAY-AFTER', '12.00', '2026-11-15 12:00:00')]];

        $this->reconcile();
        $this->assertSame(['PAY-AFTER'], GatewayTransaction::query()->pluck('reference')->all());
        $this->assertSame('2026-11-13T22:00:00Z', $this->searches[0]['start_date'], 'a day more is read than judged');

        // A rehearsal: an earlier date compares WHMCS's days too, and its imported payment matches by its id.
        $this->configure(['reconcile_from' => '2026-11-01']);
        $this->reconcile('--days=30');
        $this->assertSame(GatewayTransaction::NOT_RECORDED, $this->line('PAY-WHMCS-MISSED')->kind);
        $this->assertSame(GatewayTransaction::NOT_AT_GATEWAY, $this->line('PAY-WHMCS')->kind);

        // Off: PayPal is not called at all.
        $this->configure(['reconcile_from' => '']);
        $this->searches = [];
        $this->assertSame(0, $this->reconcile());
        $this->assertSame([], $this->searches);
        $this->assertSame([], GatewayReconcile::gateways());

        // A date still to come (set ahead of the cut-over): nothing to compare yet, and no call.
        $this->configure(['reconcile_from' => '2026-12-01 22:00']);
        $this->assertSame(0, $this->reconcile());
        $this->assertSame([], $this->searches);

        // Not a date: said, never taken for «off».
        $this->configure(['reconcile_from' => 'next week']);
        $this->assertSame(1, $this->reconcile());
        $this->assertSame([], $this->searches);
        $this->assertSame(1, DB::table('email_logs')->where('subject', 'PayPal transactions not read')->count());
    }

    public function test_a_span_longer_than_a_month_is_asked_in_parts(): void
    {
        $this->configure(['reconcile_from' => '2026-09-01']);
        $this->pages = [[$this->row('PAY-OLD', '5.00', '2026-10-15 10:00:00')]];

        $this->assertSame(0, $this->reconcile('--days=45'));

        $this->assertCount(2, $this->searches);
        foreach ($this->searches as $search) {
            $this->assertLessThanOrEqual(31, Carbon::parse($search['start_date'])->diffInDays(Carbon::parse($search['end_date'])));
        }
        $this->assertSame($this->searches[0]['end_date'], $this->searches[1]['start_date']);
        $this->assertSame(1, GatewayTransaction::query()->where('reference', 'PAY-OLD')->count(), 'listed in both parts, kept once');
        $this->assertSame(2, $this->reconcile('--days=many'));
    }

    public function test_a_difference_closes_by_itself_once_it_is_one_no_more(): void
    {
        $gone = $this->recorded('PAY-TYPO', 7.00, '2026-11-17 10:00:00');
        $this->pages = [[$this->row('PAY-LATE', '20.00', '2026-11-18 10:00:00')]];
        $this->reconcile();
        $this->assertSame(2, GatewayTransaction::query()->where('status', GatewayTransaction::OPEN)->count());

        // Staff record the one and delete the other; ten days later PayPal's week no longer reaches either.
        $this->recorded('PAY-LATE', 20.00, '2026-11-20 12:00:00');
        $gone->delete();
        $this->travel(10)->days();
        $this->pages = [[]];
        $this->reconcile();

        $late = $this->line('PAY-LATE');
        $this->assertSame([GatewayTransaction::MATCHED, GatewayTransaction::OK], [$late->kind, $late->status]);
        $this->assertNotNull($late->resolved_at);
        $this->assertNull($this->line('PAY-TYPO'), 'on neither side now');
        $this->assertSame([], (new MoneyCheck)->run(['gateway_unreconciled']));
    }

    public function test_a_known_difference_that_becomes_another_one_is_open_again(): void
    {
        $this->pages = [[$this->row('PAY-K', '20.00', '2026-11-18 10:00:00')]];
        $this->reconcile();
        $this->line('PAY-K')->update(['status' => GatewayTransaction::ACKNOWLEDGED, 'note' => 'will record it', 'acknowledged_at' => now()]);

        $this->recorded('PAY-K', 2.00, '2026-11-20 11:00:00'); // recorded since, with a typo
        $this->reconcile();

        $row = $this->line('PAY-K');
        $this->assertSame([GatewayTransaction::MISMATCH, GatewayTransaction::OPEN, 'will record it'], [$row->kind, $row->status, $row->note]);
    }

    public function test_a_payment_on_its_way_another_gateways_row_and_one_recorded_by_hand(): void
    {
        $processing = $this->recorded('PAY-PROCESSING', 9.00, '2026-11-18 10:00:00');
        DB::table('invoice_transactions')->where('id', $processing->id)->update(['status' => InvoiceTransactionStatus::Processing->value]);
        $other = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $stripe = $this->recorded('PAY-SAME-ID', 9.00, '2026-11-18 10:00:00');
        DB::table('invoice_transactions')->where('id', $stripe->id)->update(['gateway_id' => $other->id]);
        $hand = $this->recorded('PAY-BY-HAND', 9.00, '2026-11-18 10:00:00');
        DB::table('invoice_transactions')->where('id', $hand->id)->update(['gateway_id' => null]);
        $this->pages = [[$this->row('PAY-PROCESSING', '9.00', '2026-11-18 10:00:00'), $this->row('PAY-SAME-ID', '9.00', '2026-11-18 10:00:00'),
            $this->row('PAY-BY-HAND', '9.00', '2026-11-18 10:00:00')]];

        $this->reconcile();

        $this->assertNull($this->line('PAY-PROCESSING'), 'judged once it settles');
        $this->assertSame(GatewayTransaction::NOT_RECORDED, $this->line('PAY-SAME-ID')->kind, 'never another gateway\'s row');
        $this->assertSame(GatewayTransaction::MATCHED, $this->line('PAY-BY-HAND')->kind, 'what the legacy IPN told staff to record by hand');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_page_shows_them_all_and_staff_mark_a_difference_known_with_a_note(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->recorded('PAY-FINE', 12.00, '2026-11-18 09:00:00');
        $this->pages = [[$this->row('PAY-FINE', '12.00', '2026-11-18 09:00:00'), $this->row('PAY-ODD', '20.00', '2026-11-18 10:00:00')]];
        $this->reconcile();
        $fine = $this->line('PAY-FINE');
        $odd = $this->line('PAY-ODD');
        $role = fn (array $permissions) => Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        $clerk = $role(['admin.invoices.viewAny']);
        $reader = $role(['admin.gateway_transactions.viewAny']);
        $marker = $role(['admin.gateway_transactions.viewAny', 'admin.gateway_transactions.update']);

        $this->actingAs($clerk);
        $this->assertFalse(GatewayTransactionResource::canAccess());
        $this->actingAs($reader);
        $this->assertTrue(GatewayTransactionResource::canAccess());
        $this->assertFalse(GatewayTransactionResource::canCreate());
        $this->assertFalse(GatewayTransactionResource::canEdit($odd));
        $this->assertFalse(GatewayTransactionResource::canDelete($odd));
        $this->livewireAs($reader)->test(ListGatewayTransactions::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$fine, $odd])
            ->assertTableActionHidden('acknowledge', $odd);
        $this->assertSame(1, collect(NeedsAttention::report()['rows'])->filter(fn (array $row) => str_contains($row['title'], 'PayPal transaction'))->count());

        $before = $this->money();
        $this->livewireAs($marker)->test(ListGatewayTransactions::class)
            ->assertTableActionVisible('acknowledge', $odd)
            ->assertTableActionHidden('acknowledge', $fine)
            ->callTableAction('acknowledge', $odd, data: ['note' => ''])
            ->assertHasTableActionErrors(['note' => 'required']);
        $this->assertSame(GatewayTransaction::OPEN, $odd->fresh()->status);
        $this->livewireAs($marker)->test(ListGatewayTransactions::class)
            ->callTableAction('acknowledge', $odd, data: ['note' => 'the owner\'s own account, not a customer']);
        $odd->refresh();
        $this->assertSame([GatewayTransaction::ACKNOWLEDGED, 'the owner\'s own account, not a customer', $marker->id], [$odd->status, $odd->note, $odd->acknowledged_by]);
        $this->assertSame($before, $this->money());
        $this->assertSame([], (new MoneyCheck)->run(['gateway_unreconciled']));
        $this->assertSame(0, collect(NeedsAttention::report()['rows'])->filter(fn (array $row) => str_contains($row['title'], 'PayPal transaction'))->count());

        $this->livewireAs($marker)->test(ListGatewayTransactions::class)->callTableAction('reopen', $odd);
        $this->assertSame(GatewayTransaction::OPEN, $odd->fresh()->status);
        $this->assertCount(1, (new MoneyCheck)->run(['gateway_unreconciled']));
    }
}
