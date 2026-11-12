<?php

namespace Tests\Feature;

use App\Admin\Resources\GatewayTransactionResource\Pages\ListGatewayTransactions;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
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
 * Adversarial review of #810 (gateways:reconcile, the daily read-only comparison of PayPal's own list with what is
 * recorded here). One test per finding (test_adv_810_N_*): each fails on the code as reviewed. The tests named
 * test_adv_810_sound_* pin down what was attacked and held.
 *
 * PayPal is faked, and unlike the author's harness the fake answers as Transaction Search does: only the rows whose
 * date is within the span asked for.
 */
class GatewayReconcileAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-m.sandbox.paypal.com';

    private User $user;

    private Gateway $gateway;

    /** @var list<array<string, mixed>> every transaction_info PayPal's account holds */
    private array $atPayPal = [];

    private int $perPage = 500;

    /** @var list<array<string, mixed>> the query of every search made */
    private array $searches = [];

    /** @var list<string> the method and URL of every request made */
    private array $requests = [];

    /** @var list<\Closure> answers given instead, first come first */
    private array $instead = [];

    /** false: PayPal does not say up to when its list is complete */
    private string|false|null $refreshed = null;

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
            self::API . '/v1/oauth2/token' => function (Request $request) {
                $this->requests[] = $request->method() . ' ' . parse_url($request->url(), PHP_URL_PATH);

                return Http::response(['access_token' => 'token']);
            },
            self::API . '/v1/reporting/transactions*' => function (Request $request) {
                $this->requests[] = $request->method() . ' ' . parse_url($request->url(), PHP_URL_PATH);
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->searches[] = $query;
                if ($this->instead !== []) {
                    return array_shift($this->instead)();
                }
                $from = Carbon::parse($query['start_date']);
                $to = Carbon::parse($query['end_date']);
                $within = array_values(array_filter($this->atPayPal, fn (array $info) => Carbon::parse($info['transaction_initiation_date'])->betweenIncluded($from, $to)));
                $pages = array_chunk($within, $this->perPage);
                $page = (int) ($query['page'] ?? 1);

                return Http::response([
                    'transaction_details' => array_map(fn (array $info) => ['transaction_info' => $info], $pages[$page - 1] ?? []),
                    'page' => $page, 'total_pages' => max(1, count($pages)),
                ] + ($this->refreshed === false ? [] : ['last_refreshed_datetime' => $this->refreshed ?? now()->utc()->subHours(3)->format('Y-m-d\TH:i:sO')]));
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
            'transaction_initiation_date' => Carbon::parse($at)->utc()->format('Y-m-d\TH:i:sO'),
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

    /** @return array<string, int> what must never move */
    private function money(): array
    {
        return ['payments' => DB::table('invoice_transactions')->count(), 'journal' => DB::table('money_events')->count(),
            'refunds' => DB::table('gateway_refunds')->count(), 'credits' => DB::table('credits')->count(),
            'paid' => DB::table('invoices')->where('status', 'paid')->count()];
    }

    /** @return list<array{rule: string, subject: string, detail: string}> */
    private function said(): array
    {
        return (new MoneyCheck)->run(['gateway_unreconciled']);
    }

    /**
     * ADV-810-1. The docs promise that a difference staff dealt with closes «however old it is» (they recorded the
     * payment). It does not when they record it on the PayPal gateway more than the window (7 days + the margin day)
     * after PayPal took it: the payment is new here (created today, within the window) while PayPal's list of the week
     * no longer reaches the day it was taken, so the very row that PayPal's list produced is turned into «recorded
     * here, not in PayPal's list» and the money check repeats that instead.
     */
    public function test_adv_810_1_a_not_recorded_difference_staff_record_later_is_not_turned_into_not_at_the_gateway(): void
    {
        $this->atPayPal = [$this->row('PAY-LATE', '20.00', '2026-11-18 10:00:00')];
        $this->reconcile();
        $this->assertSame([GatewayTransaction::NOT_RECORDED, GatewayTransaction::OPEN], [$this->line('PAY-LATE')->kind, $this->line('PAY-LATE')->status]);

        // The weekly reminder of the money check: staff record PayPal's payment by its id, on the PayPal gateway.
        $this->travelTo(Carbon::parse('2026-11-28 06:10:00'));
        $this->recorded('PAY-LATE', 20.00, '2026-11-27 13:00:00');
        $this->assertSame(0, $this->reconcile());

        $row = $this->line('PAY-LATE');
        $this->assertSame([GatewayTransaction::MATCHED, GatewayTransaction::OK], [$row->kind, $row->status],
            'PayPal listed this very transaction on 2026-11-18 (the row was read from its list): recorded now, it is on both sides');
        $this->assertSame([], $this->said());
    }

    /**
     * ADV-810-2. The same root without staff: a payment recorded here more than a day after PayPal's date for it (an
     * eCheck or a payment under review that completes days later, a webhook PayPal re-sent for days) is matched while
     * PayPal's week reaches its date, and becomes «not in PayPal's list» on the first day the week has slid past
     * PayPal's date but not past ours. A matched row is silently turned into an open difference, and mailed.
     */
    public function test_adv_810_2_a_matched_payment_recorded_days_after_paypals_date_stays_matched_as_the_window_slides(): void
    {
        $this->atPayPal = [$this->row('PAY-SLOW', '15.00', '2026-11-16 10:00:00')];
        $this->recorded('PAY-SLOW', 15.00, '2026-11-18 09:00:00'); // cleared two days later
        $this->reconcile();
        $this->assertSame([GatewayTransaction::MATCHED, GatewayTransaction::OK], [$this->line('PAY-SLOW')->kind, $this->line('PAY-SLOW')->status]);

        // Every day of the week after: nothing changed on either side.
        foreach (['2026-11-21', '2026-11-22', '2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26'] as $day) {
            $this->travelTo(Carbon::parse($day . ' 06:10:00'));
            $this->assertSame(0, $this->reconcile());
            $row = $this->line('PAY-SLOW');
            $this->assertSame([GatewayTransaction::MATCHED, GatewayTransaction::OK], [$row->kind, $row->status], "on {$day}: " . $row->describe());
            $this->assertSame([], $this->said(), "on {$day}");
        }
    }

    /**
     * ADV-810-3. «Never compare half a list»: a 200 whose body is not PayPal's answer (an HTML page of a proxy or a
     * maintenance notice, an empty body, JSON without `transaction_details`) is taken for a complete, empty list. Every
     * payment of the week is then «not in PayPal's list», matched rows are opened, the command exits 0 and no «not
     * read» mail is sent.
     */
    public function test_adv_810_3_a_200_that_is_not_paypals_answer_is_not_an_empty_list(): void
    {
        $this->atPayPal = [$this->row('PAY-FINE', '12.00', '2026-11-18 09:00:00')];
        $this->recorded('PAY-FINE', 12.00, '2026-11-18 09:00:05');
        $this->reconcile();
        $before = GatewayTransaction::query()->get()->toArray();
        $this->assertSame(GatewayTransaction::MATCHED, $this->line('PAY-FINE')->kind);

        $this->travel(1)->days();
        $this->instead = [fn () => Http::response('<html><body>Service temporarily unavailable</body></html>', 200, ['Content-Type' => 'text/html'])];
        $exit = $this->reconcile();

        $this->assertSame($before, GatewayTransaction::query()->get()->toArray(), 'nothing compared: ' . $this->line('PAY-FINE')->describe());
        $this->assertSame(1, $exit, 'a list that was not read fails loudly');
        $this->assertSame(1, DB::table('email_logs')->where('subject', 'PayPal transactions not read')->count());
    }

    /**
     * ADV-810-3 (second face): the same on a later page. Page 1 says there are two pages; page 2 comes back 200 without
     * PayPal's JSON. The loop reads `total_pages` of the last answer (missing: 1) and stops: half a list is compared.
     */
    public function test_adv_810_4_a_later_page_that_is_not_paypals_answer_is_not_the_end_of_the_list(): void
    {
        $this->recorded('PAY-PAGE-1', 5.00, '2026-11-18 09:00:00');
        $this->recorded('PAY-PAGE-2', 6.00, '2026-11-18 10:00:00');
        $this->instead = [
            fn () => Http::response(['transaction_details' => [['transaction_info' => $this->row('PAY-PAGE-1', '5.00', '2026-11-18 09:00:00')]],
                'page' => 1, 'total_pages' => 2, 'last_refreshed_datetime' => '2026-11-20T09:00:00+0000']),
            fn () => Http::response('', 200),
        ];

        $exit = $this->reconcile();

        $this->assertSame([], GatewayTransaction::query()->get()->map->describe()->all(),
            'page 2 was never read: nothing may be said of what it would have listed; half a list is never compared');
        $this->assertSame(1, $exit);
    }

    /**
     * ADV-810-5 (a choice the docs state: «pending or denied is not judged»; filed for the owner to decide). A payment
     * recorded here as succeeded that PayPal lists as DENIED is the one difference where this site holds money PayPal
     * never gave, and it is invisible: the denied row counts as «listed», so it is not «not at the gateway» either. A
     * row that was open as «not at the gateway» is even closed as «matched» by it.
     */
    public function test_adv_810_5_a_payment_succeeded_here_and_denied_at_paypal_is_a_difference(): void
    {
        $this->recorded('PAY-DENIED', 30.00, '2026-11-18 09:00:05');
        $this->atPayPal = [$this->row('PAY-DENIED', '30.00', '2026-11-18 09:00:00', 'T0006', 'EUR', ['transaction_status' => 'D'])];

        $this->reconcile();

        $this->assertSame(1, GatewayTransaction::query()->where('status', GatewayTransaction::OPEN)->count(),
            'paid here, denied at PayPal: staff are told');
    }

    /**
     * ADV-810-6. A site whose time zone is not UTC (Europe/Athens here): PayPal's `last_refreshed_datetime` is
     * compared with `created_at` as text, in PayPal's own offset. A payment recorded here well before the moment
     * PayPal's list is complete up to, and missing from it, is not seen that day (it is the next day: a day's delay in
     * Greece; in a zone west of UTC the same slip judges payments PayPal cannot list yet).
     */
    public function test_adv_810_6_the_end_of_paypals_list_is_a_moment_not_a_wall_clock_in_another_zone(): void
    {
        $zone = date_default_timezone_get();
        config(['app.timezone' => 'Europe/Athens']);
        date_default_timezone_set('Europe/Athens');
        try {
            $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'Europe/Athens')); // 10:00 UTC
            $this->refreshed = '2026-11-20T09:00:00+0000'; // complete up to 11:00 in Athens
            $this->recorded('PAY-TYPO', 7.00, '2026-11-20 09:30:00'); // 07:30 UTC: an hour and a half before that
            $this->atPayPal = [];

            $this->reconcile();

            $this->assertNotNull($this->line('PAY-TYPO'), 'recorded 90 minutes before the end of PayPal\'s list and not in it');
        } finally {
            config(['app.timezone' => $zone]);
            date_default_timezone_set($zone);
        }
    }

    // ---- Attacked and found sound -------------------------------------------------------------------------------------

    public function test_adv_810_sound_a_second_run_changes_nothing_and_only_ever_gets(): void
    {
        $this->recorded('PAY-A', 12.50, '2026-11-18 09:00:05');
        $this->recorded('PAY-GONE', 3.00, '2026-11-17 09:00:05');
        $this->atPayPal = [$this->row('PAY-A', '12.50', '2026-11-18 09:00:00'), $this->row('PAY-B', '9.90', '2026-11-19 03:00:00'),
            $this->row('PAY-C', '1.00', '2026-11-19 04:00:00')];
        $before = $this->money();
        $this->reconcile();
        $this->line('PAY-C')->update(['status' => GatewayTransaction::ACKNOWLEDGED, 'note' => 'known', 'acknowledged_at' => now()]);
        $rows = GatewayTransaction::query()->orderBy('id')->get()->map->only(['reference', 'kind', 'status', 'note', 'amount', 'recorded_amount'])->all();
        $said = $this->said();

        $this->reconcile();
        $this->reconcile();

        $this->assertSame($rows, GatewayTransaction::query()->orderBy('id')->get()->map->only(['reference', 'kind', 'status', 'note', 'amount', 'recorded_amount'])->all());
        $this->assertSame($said, $this->said());
        $this->assertSame($before, $this->money());
        $this->assertSame(['POST /v1/oauth2/token', 'GET /v1/reporting/transactions'], array_values(array_unique($this->requests)), 'the token, then GETs only');
    }

    public function test_adv_810_sound_amount_and_currency_spellings_and_the_pages(): void
    {
        $this->recorded('PAY-TEN', 10.00, '2026-11-18 09:00:05');
        $this->recorded('PAY-LOWER', 10.00, '2026-11-18 09:10:05');
        $this->recorded('PAY-BIG', 123456.78, '2026-11-18 09:20:05');
        $this->recorded('12345678901234567', 4.00, '2026-11-18 09:30:05'); // an id of digits only
        $this->atPayPal = [
            $this->row('PAY-TEN', '10.0', '2026-11-18 09:00:00'), $this->row('PAY-LOWER', '10', '2026-11-18 09:10:00', 't0006', 'eur'),
            $this->row('PAY-BIG', '123456.78', '2026-11-18 09:20:00'), $this->row('12345678901234567', '4.00', '2026-11-18 09:30:00'),
            $this->row('PAY-ZERO', '0.00', '2026-11-18 09:40:00'),
        ];
        $this->perPage = 2; // three pages

        $this->assertSame(0, $this->reconcile());

        $this->assertSame(['1', '2', '3'], array_column($this->searches, 'page'));
        $this->assertSame(4, GatewayTransaction::query()->where('kind', GatewayTransaction::MATCHED)->where('status', GatewayTransaction::OK)->count());
        $this->assertNull($this->line('PAY-ZERO'));
        $this->assertSame([], $this->said());
    }

    public function test_adv_810_sound_a_list_of_no_pages_and_one_that_does_not_say_how_far_it_goes(): void
    {
        $this->recorded('PAY-OLD-TYPO', 7.00, '2026-11-17 10:00:00');
        $this->recorded('PAY-FIVE-HOURS-AGO', 7.00, '2026-11-20 07:00:00');
        $this->instead = [fn () => Http::response(['transaction_details' => [], 'page' => 1, 'total_pages' => 0])];

        $this->assertSame(0, $this->reconcile());

        $this->assertCount(1, $this->searches);
        $this->assertSame(['PAY-OLD-TYPO'], GatewayTransaction::query()->pluck('reference')->all(), 'unsaid: six hours behind');
    }

    public function test_adv_810_sound_a_failed_read_keeps_what_staff_marked_and_the_money_check_says_the_same_each_day(): void
    {
        $this->atPayPal = [$this->row('PAY-K', '20.00', '2026-11-18 10:00:00'), $this->row('PAY-O', '21.00', '2026-11-18 11:00:00')];
        $this->reconcile();
        $this->line('PAY-K')->update(['status' => GatewayTransaction::ACKNOWLEDGED, 'note' => 'known', 'acknowledged_at' => now()]);
        $rows = GatewayTransaction::query()->get()->toArray();
        $down = fn (string $debug) => fn () => Http::response(['name' => 'INTERNAL_SERVICE_ERROR', 'message' => 'An internal service error occurred.', 'debug_id' => $debug], 500);

        $said = [];
        foreach (['aaa111', 'bbb222', 'ccc333'] as $debug) {
            $this->travel(1)->days();
            $this->instead = [$down($debug)];
            $this->assertSame(1, $this->reconcile());
            $said[] = $this->said();
        }

        $this->assertSame($rows, GatewayTransaction::query()->get()->toArray());
        $this->assertSame(1, DB::table('email_logs')->where('subject', 'PayPal transactions not read')->count(), 'one mail a streak');
        $this->assertSame($said[1], $said[2], 'no debug id in what the money check repeats');
        $this->assertStringContainsString('its list was not read since 2026-11-20 12:00 (HTTP 500 INTERNAL_SERVICE_ERROR)', json_encode($said[2]));
        $this->assertStringNotContainsString('secret', (string) DB::table('email_logs')->where('subject', 'PayPal transactions not read')->value('body'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_adv_810_sound_only_staff_with_update_mark_and_only_an_open_row(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->recorded('PAY-FINE', 12.00, '2026-11-18 09:00:00');
        $this->atPayPal = [$this->row('PAY-FINE', '12.00', '2026-11-18 09:00:00'), $this->row('<script>alert(1)</script>', '20.00', '2026-11-18 10:00:00')];
        $this->reconcile();
        $fine = $this->line('PAY-FINE');
        $odd = $this->line('<script>alert(1)</script>');
        $role = fn (array $permissions) => Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        $reader = $role(['admin.gateway_transactions.viewAny']);
        $updater = $role(['admin.gateway_transactions.update']); // update without viewAny
        $marker = $role(['admin.gateway_transactions.viewAny', 'admin.gateway_transactions.update']);

        $this->assertFalse($reader->can('update', $odd));
        $this->assertFalse($updater->can('update', $odd));
        $this->assertFalse($this->user->can('update', $odd), 'a customer');
        $this->assertFalse($marker->can('delete', $odd));

        try {
            $this->livewireAs($reader)->test(ListGatewayTransactions::class)->callTableAction('acknowledge', $odd, data: ['note' => 'mine']);
        } catch (\Throwable) {
            // refused one way or another
        }
        $this->assertSame(GatewayTransaction::OPEN, $odd->fresh()->status);

        try {
            $this->livewireAs($marker)->test(ListGatewayTransactions::class)->callTableAction('acknowledge', $fine, data: ['note' => 'a row that is fine']);
        } catch (\Throwable) {
            // refused one way or another
        }
        $this->assertSame([GatewayTransaction::OK, null], [$fine->fresh()->status, $fine->fresh()->note]);

        $this->livewireAs($marker)->test(ListGatewayTransactions::class)
            ->callTableAction('acknowledge', $odd, data: ['note' => '<img src=x onerror=alert(2)>']);
        $this->assertSame(GatewayTransaction::ACKNOWLEDGED, $odd->fresh()->status);
        $html = $this->livewireAs($marker)->test(ListGatewayTransactions::class)->html();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
    }

    public function test_adv_810_sound_reconcile_from_is_read_in_the_sites_zone(): void
    {
        $zone = date_default_timezone_get();
        config(['app.timezone' => 'Europe/Athens']);
        date_default_timezone_set('Europe/Athens');
        try {
            $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'Europe/Athens'));
            $this->recorded('PAY-AFTER', 5.00, '2026-11-14 22:30:00');
            $this->atPayPal = [
                $this->row('PAY-BEFORE', '8.00', '2026-11-14 21:59:00'), // 19:59 UTC: before «from» (22:00 in Athens)
                $this->row('PAY-AFTER', '5.00', '2026-11-14 22:29:50'),
            ];

            $this->reconcile();

            $this->assertSame('2026-11-13T20:00:00Z', $this->searches[0]['start_date']);
            $this->assertSame(['PAY-AFTER'], GatewayTransaction::query()->pluck('reference')->all());
            $this->assertSame([GatewayTransaction::MATCHED, '2026-11-14 22:29:50'], [$this->line('PAY-AFTER')->kind, $this->line('PAY-AFTER')->occurred_at->format('Y-m-d H:i:s')]);
            $this->assertSame(GatewayReconcile::state()['PayPal']['error'], null);
        } finally {
            config(['app.timezone' => $zone]);
            date_default_timezone_set($zone);
        }
    }
}
