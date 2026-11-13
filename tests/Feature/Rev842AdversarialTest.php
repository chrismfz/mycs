<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\GatewayTransaction;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Adversarial review of #842 (PayPal Transaction Search: a 200 without transaction_details but with total_items the
 * integer 0 is an empty list). One test per finding (test_adv_842_N_*), each failing on the PR as reviewed; the
 * test_adv_842_sound_* ones pin down attacks that held.
 */
class Rev842AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-m.sandbox.paypal.com';

    private User $user;

    private Gateway $gateway;

    /** @var list<array<string, mixed>> every transaction_info PayPal's account holds */
    private array $atPayPal = [];

    /** @var list<array<string, mixed>> */
    private array $searches = [];

    /** @var list<\Closure> answers given instead, first come first */
    private array $instead = [];

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
            self::API . '/v1/oauth2/token' => fn () => Http::response(['access_token' => 'token']),
            self::API . '/v1/reporting/transactions*' => function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->searches[] = $query;
                if ($this->instead !== []) {
                    return array_shift($this->instead)();
                }
                $from = Carbon::parse($query['start_date']);
                $to = Carbon::parse($query['end_date']);
                $within = array_values(array_filter($this->atPayPal, fn (array $info) => Carbon::parse($info['transaction_initiation_date'])->betweenIncluded($from, $to)));

                // As PayPal answers an empty span: no transaction_details, total_items 0.
                return Http::response(($within === [] ? [] : [
                    'transaction_details' => array_map(fn (array $info) => ['transaction_info' => $info], $within),
                ]) + ['page' => 1, 'total_items' => count($within), 'total_pages' => $within === [] ? 0 : 1,
                    'last_refreshed_datetime' => now()->utc()->subHours(3)->format('Y-m-d\TH:i:sO')]);
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

    /** @return array<string, mixed> */
    private function row(string $id, string $value, string $at): array
    {
        return ['transaction_id' => $id, 'transaction_event_code' => 'T0006', 'transaction_status' => 'S',
            'transaction_initiation_date' => Carbon::parse($at)->utc()->format('Y-m-d\TH:i:sO'),
            'transaction_amount' => ['currency_code' => 'EUR', 'value' => $value]];
    }

    private function recorded(string $id, float $amount, string $at): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $amount]);
        $payment = ExtensionHelper::addPayment($invoice->id, 'PayPal', $amount, null, $id, currency: 'EUR');
        DB::table('invoice_transactions')->where('id', $payment->id)->update(['created_at' => $at]);
    }

    private function reconcile(): int
    {
        return Artisan::call('gateways:reconcile');
    }

    private function notReadMails(): int
    {
        return DB::table('email_logs')->where('subject', 'PayPal transactions not read')->count();
    }

    /**
     * ADV-842-1. The exception reopens ADV-810-4 on a later page. Page 1 lists one payment and says there are two pages
     * (two items). Page 2 comes back 200 without transaction_details but with total_items 0, which contradicts page 1:
     * it cannot be «the span has none». The PR takes it as an empty page; its total_pages is missing (1), the loop
     * stops, and half a list is compared: the payment page 2 would have listed is opened as «not in PayPal's list»,
     * exit 0, no «not read» mail. «Nothing in the span» can only be said by the first page of a part.
     */
    public function test_adv_842_1_a_later_page_saying_total_items_0_is_not_the_end_of_a_list_page_1_said_was_longer(): void
    {
        $this->recorded('PAY-PAGE-1', 5.00, '2026-11-18 09:00:00');
        $this->recorded('PAY-PAGE-2', 6.00, '2026-11-18 10:00:00');
        $this->instead = [
            fn () => Http::response(['transaction_details' => [['transaction_info' => $this->row('PAY-PAGE-1', '5.00', '2026-11-18 09:00:00')]],
                'page' => 1, 'total_items' => 2, 'total_pages' => 2, 'last_refreshed_datetime' => '2026-11-20T09:00:00+0000']),
            fn () => Http::response(['page' => 2, 'total_items' => 0, 'last_refreshed_datetime' => '2026-11-20T09:00:00+0000']),
        ];

        $exit = $this->reconcile();

        $this->assertSame([], GatewayTransaction::query()->get()->map->describe()->all(),
            'page 2 was not a list of anything: nothing may be compared');
        $this->assertSame(1, $exit, 'half a list fails the read');
        $this->assertSame(1, $this->notReadMails());
    }

    /** Only the integer 0 with transaction_details absent: any other shape of «no rows» still fails the read. */
    public function test_adv_842_sound_other_spellings_of_zero_or_rows_that_are_not_a_list_still_fail(): void
    {
        $bodies = [
            ['page' => 1, 'total_items' => false, 'total_pages' => 0],
            ['page' => 1, 'total_items' => null, 'total_pages' => 0],
            ['page' => 1, 'total_items' => 0, 'total_pages' => 0, 'transaction_details' => ''],
            ['page' => 1, 'total_items' => 0, 'total_pages' => 0, 'transaction_details' => new \stdClass],
            ['page' => 1, 'total_items' => 0, 'total_pages' => 0, 'transaction_details' => 0],
        ];
        foreach ($bodies as $body) {
            Cache::flush();
            $this->instead = [fn () => Http::response($body + ['last_refreshed_datetime' => '2026-11-20T09:00:00+0000'])];

            $this->assertSame(1, $this->reconcile(), json_encode($body));
        }
        // 0.0 (a float, as raw JSON), a JSON array at the top (not PayPal's object) and a bare 0 are not an answer either.
        foreach (['{"page":1,"total_items":0.0,"total_pages":0}', '[{"total_items":0}]', '0'] as $raw) {
            Cache::flush();
            $this->instead = [fn () => Http::response($raw, 200, ['Content-Type' => 'application/json'])];
            $this->assertSame(1, $this->reconcile(), $raw);
        }
    }

    /**
     * A span longer than one call (40 days: two parts). The older part is empty (PayPal's empty answer), the newer one
     * lists the payment: both read, matched, nothing said. And the reverse: the newer part empty, the payment recorded
     * here then shows as not at PayPal, which is what an empty list read in full means.
     */
    public function test_adv_842_sound_an_empty_part_of_a_longer_span_is_read_and_the_other_part_is_kept(): void
    {
        $this->configure(['reconcile_from' => '2026-09-01 00:00', 'reconcile_days' => '40']);
        $this->atPayPal = [$this->row('PAY-NEW', '9.00', '2026-11-18 09:00:00')];
        $this->recorded('PAY-NEW', 9.00, '2026-11-18 09:00:05');

        $this->assertSame(0, $this->reconcile());

        $this->assertCount(2, $this->searches, 'two parts asked');
        $this->assertSame(GatewayTransaction::MATCHED, GatewayTransaction::query()->where('reference', 'PAY-NEW')->value('kind'));
        $this->assertSame(0, GatewayTransaction::query()->where('status', GatewayTransaction::OPEN)->count());
        $this->assertSame(0, $this->notReadMails());
    }

    /**
     * After a failed day (the «not read» mail sent once), an empty span read as PayPal answers it ends the streak: the
     * next failure mails again, and the open rows of the earlier days are not touched by the empty read outside its span.
     */
    public function test_adv_842_sound_an_empty_read_ends_a_not_read_streak_and_the_next_failure_mails_again(): void
    {
        $this->instead = [fn () => Http::response('', 200)];
        $this->assertSame(1, $this->reconcile());
        $this->assertSame(1, $this->notReadMails());

        $this->travel(1)->days();
        $this->assertSame(0, $this->reconcile(), 'empty span, read');

        $this->travel(1)->days();
        $this->instead = [fn () => Http::response(['page' => 1, 'total_pages' => 1], 200)];
        $this->assertSame(1, $this->reconcile());
        $this->assertSame(2, $this->notReadMails(), 'a new streak, a new mail');
    }
}
