<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\Registrars\CnicRegistrar;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * CentralNic Reseller, step 3 (owner 2026-10-21: «ας υπάρχει η ανανέωση»): a paid renewal renewed at CNIC through the
 * renewal service's locks and checks: a period CNIC has already is adopted, never charged; a refusal is staff's; no
 * answer is pending, never asked again blindly.
 */
class CnicRenewalTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $sent = [];

    private string $paidUntil = '2026-11-01';

    private ?string $renewAnswer = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            parse_str($request->body(), $form);
            $command = (string) ($form['s_command'] ?? '');
            $this->sent[] = $command;
            if (str_starts_with($command, 'COMMAND=RenewDomain')) {
                if ($this->renewAnswer === null) {
                    $this->paidUntil = Carbon::parse($this->paidUntil)->addYear()->toDateString(); // done there

                    return Http::response("[RESPONSE]\ncode = 200\ndescription = Command completed successfully\nEOF\n");
                }

                return is_int($this->renewAnswer) ? Http::response('<html>Gateway</html>', $this->renewAnswer) : Http::response($this->renewAnswer);
            }

            return Http::response("[RESPONSE]\ncode = 200\ndescription = OK\nproperty[PAIDUNTIL DATE][0] = {$this->paidUntil} 00:00:00.0\n"
                . "property[STATUS][0] = clientTransferProhibited\nproperty[TRANSFER LOCK][0] = 1\nproperty[NAMESERVER][0] = ns1.example.net\nEOF\n");
        });
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paidRenewal(): array
    {
        $cnic = DomainRegistrarConnection::query()->create(['registrar' => 'cnic', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $cnic->storeCredentials(['username' => 'myipreseller', 'password' => 'Cnic-Secret-1'], ['password']);
        $tld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'grace_days' => 40, 'redemption_days' => 30]);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '23.33']);
        $domain = Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $cnic->id, 'status' => DomainStatus::Active, 'expires_at' => $this->paidUntil,
            'currency_code' => 'EUR', 'auto_renew' => true]);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->latest('id')->firstOrFail();
        (new RecordPaymentService)->handle($line->invoice, null, (float) $line->invoice->fresh()->total, transactionId: 'tx-' . $line->id);

        return [$domain, $line->fresh()];
    }

    private function renewals(): int
    {
        return collect($this->sent)->filter(fn ($c) => str_starts_with($c, 'COMMAND=RenewDomain'))->count();
    }

    public function test_a_paid_renewal_is_renewed_there_once_for_its_years(): void
    {
        [$domain, $line] = $this->paidRenewal();

        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame(DomainRenewalService::NOTHING, app(DomainRenewalService::class)->renewLine($line->id), 'applied: never again');

        $this->assertSame(1, $this->renewals());
        $this->assertStringContainsString("DOMAIN=example.com\nPERIOD=1", collect($this->sent)->first(fn ($c) => str_starts_with($c, 'COMMAND=RenewDomain')));
        $this->assertSame('2027-11-01', $domain->fresh()->expires_at->toDateString());
        $this->assertSame(DomainRegistrarLog::STATUS_OK, DomainRegistrarLog::query()->where('action', 'renew')->sole()->status);
    }

    public function test_a_period_cnic_has_already_is_adopted_never_charged(): void
    {
        [, $line] = $this->paidRenewal();
        $this->paidUntil = '2027-11-01'; // renewed there already (by hand, or its own)

        $this->assertSame(DomainRenewalService::ADOPTED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame(0, $this->renewals());
    }

    public function test_a_refusal_is_staffs_and_no_answer_is_never_asked_again_blindly(): void
    {
        [, $line] = $this->paidRenewal();
        $this->renewAnswer = "[RESPONSE]\ncode = 549\ndescription = Command failed\nEOF\n";
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame(ModuleAction::STATUS_OPEN, ModuleAction::query()->sole()->status);
        $this->assertNull($line->fresh()->applied_at);

        app(DomainRenewalService::class)->renewLine($line->id);
        $this->assertSame(1, $this->renewals(), 'refused: never asked again by the next run on its own');

        $this->renewAnswer = 504; // staff's Retry; its front: maybe charged
        app(DomainRenewalService::class)->renewLine($line->id, retry: true);
        $before = $this->renewals();
        app(DomainRenewalService::class)->renewLine($line->id);
        $this->assertSame($before, $this->renewals(), 'a renewal whose outcome is not known is never asked again blindly');
        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('action', 'renew')->latest('id')->first()->status);
    }

    public function test_its_grace_is_a_restore_never_a_renewal_without_its_fee(): void
    {
        // Ours is the day it goes there: our grace is its deletion or redemption, a restore with its fee (code review of #302).
        $this->assertFalse(app(CnicRegistrar::class)->capabilities()->renewsInGrace);
    }
}
