<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\RelationManagers\UpgradesRelationManager;
use App\Livewire\Services\Upgrade;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use MyCS\Pricing\DifferenceDiscount;
use OwenIt\Auditing\Models\Audit;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Staff's discount on an upgrade's difference (owner 2026-11-06): a percentage, an amount to charge (with tax) or waived,
 * from the service's «Upgrade/Downgrade». The invoice shows the upgrade at its full difference and the discount as a
 * negative line under it; waived, no invoice and applied at once. The renewal price and the customer's own upgrade are
 * untouched. 16 of 31 days left: Business10 (10.00) → Business20 (30.00) is 10.32 net, 12.80 with 24% tax.
 */
class UpgradeDiscountTest extends TestCase
{
    use RefreshDatabase;

    private Product $small;

    private Product $large;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 10:00:00');
        Mail::fake();
        Queue::fake();
        DB::table('notification_templates')->delete();
        (new EmailTemplateSeeder)->run();
        $this->storeSettings(['mail_disable' => false, 'credits_enabled' => true, 'credits_on_downgrade' => false, 'credits_auto_use' => false,
            'tax_enabled' => true, 'tax_type' => 'exclusive', 'eu_vat_enabled' => false]);
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $this->small = $this->product('Business10', 10.00);
        $this->large = $this->product('Business20', 30.00);
        DB::table('product_upgrades')->insert([['product_id' => $this->small->id, 'upgrade_id' => $this->large->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $price): Product
    {
        $product = Product::factory()->create(['name' => $name, 'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function service(Product $product, float $price): Service
    {
        $this->user = User::factory()->create();
        $this->user->client()->primaryContact->update(['country' => 'GR']);

        return Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $product->id,
            'plan_id' => $product->plans()->first()->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-07-31', 'domain' => 'shop.example.gr']);
    }

    private function admin(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Upg' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /** @param  array<string, mixed>  $discount */
    private function staffUpgrade(Service $service, array $discount, ?Staff $staff = null): void
    {
        $this->livewireAs($staff ?? $this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changePackage', ['product_id' => $this->large->id] + $discount)
            ->assertHasNoActionErrors();
    }

    /** @return list<array{string, string, string, ?string}> description, net, total, kind of each line */
    private function lines(Invoice $invoice): array
    {
        return $invoice->items()->orderBy('id')->get()->map(fn (InvoiceItem $line) => [(string) $line->description,
            number_format((float) $line->price, 2, '.', ''), number_format((float) $line->line_total, 2, '.', ''), $line->kind])->all();
    }

    private function totalOf(Invoice $invoice): string
    {
        return number_format($invoice->items()->get()->sum(fn (InvoiceItem $line) => $line->totalCents()) / 100, 2, '.', '');
    }

    // ------------------------------------------------------------------ percent

    public function test_a_percentage_is_a_line_of_its_own_under_the_full_difference(): void
    {
        $service = $this->service($this->small, 10.00);
        $staff = $this->admin();

        $this->staffUpgrade($service, ['discount_mode' => DifferenceDiscount::PERCENT, 'discount_value' => '10'], $staff);

        $upgrade = ServiceUpgrade::query()->sole();
        $this->assertSame(ServiceUpgrade::STATUS_PENDING, $upgrade->status);
        // 10% of 10.32 = 1.032 → 1.03 net; its tax 0.2472 → 0.25: −1.28. 12.80 − 1.28 = 11.52.
        $this->assertSame([
            ['Upgrade Business10 to Business20', '10.32', '12.80', null],
            ['Discount on the upgrade (10%)', '-1.03', '-1.28', InvoiceItem::KIND_DISCOUNT],
        ], $this->lines($upgrade->invoice));
        $this->assertSame('11.52', $this->totalOf($upgrade->invoice));
        $this->assertSame(['1.03', '10.00', $staff->id], [$upgrade->discount, $upgrade->discount_percent, $upgrade->staff_id]);
        $discountLine = $upgrade->invoice->items()->where('kind', InvoiceItem::KIND_DISCOUNT)->sole();
        $this->assertSame([ServiceUpgrade::class, $upgrade->id, '24.0000'], [$discountLine->reference_type, (int) $discountLine->reference_id, (string) $discountLine->tax_rate],
            'the upgrade\'s reference and its tax');
        $this->assertSame($this->small->id, (int) $service->fresh()->product_id, 'applied once paid');
    }

    public function test_the_discount_line_is_in_the_customers_language(): void
    {
        $this->storeSettings(['allowed_languages' => ['en', 'el']]);
        $service = $this->service($this->small, 10.00);
        $service->client->forceFill(['language' => 'el'])->save();

        $this->staffUpgrade($service, ['discount_mode' => DifferenceDiscount::PERCENT, 'discount_value' => '12.5']);

        $this->assertSame('Έκπτωση στην αναβάθμιση (12.5%)', $this->lines(ServiceUpgrade::query()->sole()->invoice)[1][0]);
    }

    // ------------------------------------------------------------------ amount to charge

    public function test_an_amount_to_charge_is_with_tax(): void
    {
        $service = $this->service($this->small, 10.00);

        // A comma, as a Greek keyboard types it.
        $this->staffUpgrade($service, ['discount_mode' => DifferenceDiscount::AMOUNT, 'discount_value' => '12,50']);

        $upgrade = ServiceUpgrade::query()->sole();
        $this->assertSame([
            ['Upgrade Business10 to Business20', '10.32', '12.80', null],
            ['Discount on the upgrade', '-0.24', '-0.30', InvoiceItem::KIND_DISCOUNT],
        ], $this->lines($upgrade->invoice));
        $this->assertSame('12.50', $this->totalOf($upgrade->invoice));
        $this->assertNull($upgrade->discount_percent);
    }

    public function test_an_amount_the_tax_rounding_cannot_reach_is_the_cent_under_and_the_summary_says_so(): void
    {
        $service = $this->service($this->small, 10.00);

        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->mountAction('changePackage')
            ->fillForm(['product_id' => $this->large->id, 'discount_mode' => DifferenceDiscount::AMOUNT, 'discount_value' => '12'])
            ->assertMountedActionModalSee(['Upgrade difference: 12.80 USD (with tax)', 'Discount: −0.81 USD', 'Due now: 11.99 USD',
                'cannot be reached exactly'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('11.99', $this->totalOf(ServiceUpgrade::query()->sole()->invoice), 'never above what staff typed');
    }

    public function test_the_summary_shows_the_full_difference_the_discount_and_what_is_due(): void
    {
        $service = $this->service($this->small, 10.00);

        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->mountAction('changePackage')
            ->fillForm(['product_id' => $this->large->id])
            ->assertMountedActionModalSee(['Discount on the difference', 'Upgrade total due: 12.80 USD (with tax'])
            ->fillForm(['discount_mode' => DifferenceDiscount::PERCENT, 'discount_value' => '10'])
            ->assertMountedActionModalSee(['Upgrade difference: 12.80 USD (with tax)', 'Discount: −1.28 USD (10%)',
                'Due now: 11.52 USD (with tax; an invoice, applied once paid)', 'Renews at: 37.20 USD per period with tax (now 12.40)']);

        $this->assertSame(0, ServiceUpgrade::query()->count(), 'nothing made before «Create order»');
    }

    // ------------------------------------------------------------------ waived

    /** @return array<string, array{array<string, string>}> */
    public static function waivers(): array
    {
        return [
            'waive' => [['discount_mode' => DifferenceDiscount::WAIVE]],
            'nothing to charge' => [['discount_mode' => DifferenceDiscount::AMOUNT, 'discount_value' => '0']],
            '100%' => [['discount_mode' => DifferenceDiscount::PERCENT, 'discount_value' => '100']],
        ];
    }

    /** @param  array<string, string>  $discount */
    #[DataProvider('waivers')]
    public function test_waived_it_is_applied_at_once_without_an_invoice_and_audited(array $discount): void
    {
        // Credit on downgrade on: a waived upgrade still gives nothing back.
        $this->storeSettings(['credits_on_downgrade' => true]);
        Config::set('audit.console', true);
        $service = $this->service($this->small, 10.00);
        $staff = $this->admin();

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changePackage', ['product_id' => $this->large->id] + $discount)
            ->assertHasNoActionErrors()
            ->assertNotified('Package changed');

        $upgrade = ServiceUpgrade::query()->sole();
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->status);
        $this->assertNull($upgrade->invoice_id);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, Credit::query()->count());
        $this->assertNull($upgrade->credit_cents);
        $this->assertSame(['10.32', $staff->id, ServiceUpgrade::SOURCE_STAFF], [$upgrade->discount, $upgrade->staff_id, $upgrade->source]);
        $this->assertSame($this->large->id, (int) $service->fresh()->product_id);
        $this->assertSame('30.00', number_format((float) $service->fresh()->price, 2, '.', ''), 'the renewal price as without a discount');
        $this->assertSame(1, EmailLog::query()->where('subject', 'like', 'Service changed:%')->count());
        // Who gave it: the upgrade's audit, by the staff member.
        $audit = Audit::query()->where('auditable_type', ServiceUpgrade::class)->where('auditable_id', $upgrade->id)
            ->where('new_values', 'like', '%discount%')->firstOrFail();
        $this->assertSame([$staff->getMorphClass(), $staff->id], [$audit->user_type, (int) $audit->user_id]);
    }

    public function test_the_package_changes_show_the_discount(): void
    {
        $service = $this->service($this->small, 10.00);
        $given = PlaceUpgrade::handle($service, $this->large, [], ServiceUpgrade::SOURCE_STAFF, null, DifferenceDiscount::PERCENT, '10');
        $this->assertSame(PlaceUpgrade::INVOICED, $given->outcome);
        $other = $this->service($this->small, 10.00);
        $waived = PlaceUpgrade::handle($other, $this->large, [], ServiceUpgrade::SOURCE_STAFF, null, DifferenceDiscount::WAIVE);
        $this->assertSame(PlaceUpgrade::APPLIED, $waived->outcome);
        $this->actingAs($this->admin(), 'staff');

        Livewire::test(UpgradesRelationManager::class, ['ownerRecord' => $service->fresh(), 'pageClass' => EditService::class])
            ->assertSee('1.03 (10%)');
        Livewire::test(UpgradesRelationManager::class, ['ownerRecord' => $other->fresh(), 'pageClass' => EditService::class])
            ->assertSee('10.32, waived');
    }

    // ------------------------------------------------------------------ refused

    /** @return array<string, array{string, string}> */
    public static function tooLarge(): array
    {
        return [
            'above 100%' => [DifferenceDiscount::PERCENT, '150'],
            'more to charge than the difference' => [DifferenceDiscount::AMOUNT, '12.81'],
            'negative' => [DifferenceDiscount::AMOUNT, '-1'],
            'not a number' => [DifferenceDiscount::PERCENT, 'ten'],
        ];
    }

    #[DataProvider('tooLarge')]
    public function test_a_discount_it_cannot_give_makes_nothing(string $mode, string $value): void
    {
        $service = $this->service($this->small, 10.00);

        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changePackage', ['product_id' => $this->large->id, 'discount_mode' => $mode, 'discount_value' => $value])
            ->assertNotified('Not changed');

        $this->assertSame(0, ServiceUpgrade::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame($this->small->id, (int) $service->fresh()->product_id);
    }

    public function test_a_downgrade_has_no_discount(): void
    {
        $service = $this->service($this->large, 30.00);

        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->mountAction('changePackage')
            ->fillForm(['product_id' => $this->small->id])
            ->assertMountedActionModalDontSee('Discount on the difference');

        $placed = PlaceUpgrade::handle($service, $this->small, [], ServiceUpgrade::SOURCE_STAFF, null, DifferenceDiscount::PERCENT, '10');
        $this->assertSame([PlaceUpgrade::DISCOUNT_REFUSED, DifferenceDiscount::REFUSED_NOTHING_DUE], [$placed->outcome, $placed->refusal]);
        $this->assertSame(0, ServiceUpgrade::query()->count(), 'rolled back');
        $this->assertSame($this->large->id, (int) $service->fresh()->product_id);
    }

    // ------------------------------------------------------------------ tax

    public function test_without_tax_the_amount_to_charge_is_the_net(): void
    {
        $this->storeSettings(['tax_enabled' => false]);
        $service = $this->service($this->small, 10.00);

        $this->staffUpgrade($service, ['discount_mode' => DifferenceDiscount::AMOUNT, 'discount_value' => '10']);

        $invoice = ServiceUpgrade::query()->sole()->invoice;
        $this->assertSame([
            ['Upgrade Business10 to Business20', '10.32', '10.32', null],
            ['Discount on the upgrade', '-0.32', '-0.32', InvoiceItem::KIND_DISCOUNT],
        ], $this->lines($invoice));
    }

    public function test_a_tax_exempt_account_gets_the_same_rate_on_both_lines(): void
    {
        $service = $this->service($this->small, 10.00);
        $service->client->forceFill(['tax_exempt' => true])->save();

        $this->staffUpgrade($service, ['discount_mode' => DifferenceDiscount::AMOUNT, 'discount_value' => '10']);

        $invoice = ServiceUpgrade::query()->sole()->invoice;
        $this->assertSame(['10.32', '-0.32'], array_column($this->lines($invoice), 2));
        $this->assertSame('10.00', $this->totalOf($invoice));
    }

    // ------------------------------------------------------------------ once paid

    public function test_paid_it_is_applied_at_the_renewal_price_without_a_discount(): void
    {
        $service = $this->service($this->small, 10.00);
        $this->staffUpgrade($service, ['discount_mode' => DifferenceDiscount::PERCENT, 'discount_value' => '10']);
        $upgrade = ServiceUpgrade::query()->sole();

        (new RecordPaymentService)->handle($upgrade->invoice, null, '11.52', transactionId: 'paid-discounted');

        $this->assertSame(Invoice::STATUS_PAID, $upgrade->invoice->fresh()->status);
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $service->refresh();
        $this->assertSame($this->large->id, (int) $service->product_id);
        $this->assertSame('30.00', number_format((float) $service->price, 2, '.', ''), 'the renewal: the catalog price, no discount');
        $this->assertSame('30.00', $upgrade->fresh()->to_price);
        $this->assertSame(1, Invoice::query()->count(), 'no «shortfall» invoice for the discount');
    }

    public function test_a_renewal_paid_meanwhile_bills_the_extra_days_at_full(): void
    {
        $service = $this->service($this->small, 10.00);
        $this->staffUpgrade($service, ['discount_mode' => DifferenceDiscount::PERCENT, 'discount_value' => '10']);
        $upgrade = ServiceUpgrade::query()->sole();
        // A renewal paid before the upgrade: a month more at the old price (ServiceUpgradeService::shortfall).
        DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-08-31']);
        $owed = number_format((float) $upgrade->fresh()->calculatePrice()->price - 10.32, 2, '.', '');
        $this->assertGreaterThan(0, (float) $owed);

        (new RecordPaymentService)->handle($upgrade->invoice, null, '11.52', transactionId: 'paid-discounted');

        $rest = Invoice::query()->whereKeyNot($upgrade->invoice_id)->sole();
        $this->assertSame($owed, number_format((float) $rest->items()->sole()->price, 2, '.', ''),
            'the days more, not the discount billed back');
    }

    /** @return array<string, array{array<string, string>, list<string>, string}> */
    public static function repriced(): array
    {
        // On 07-25 six of the 31 days are left: (30 − 10) × 6 / 31 = 3.87 net, 4.80 with tax.
        return [
            // 10% stays 10%: 0.387 → 0.39 net, 0.48 with tax.
            'a percentage' => [['discount_mode' => DifferenceDiscount::PERCENT, 'discount_value' => '10'], ['3.87', '-0.39'], '0.39'],
            // 12.50 of 12.80 was 0.24 net off 10.32; the same share of 3.87 is 0.09.
            'an amount' => [['discount_mode' => DifferenceDiscount::AMOUNT, 'discount_value' => '12.50'], ['3.87', '-0.09'], '0.09'],
        ];
    }

    /**
     * The cron reprices the charge as the days run out, and the discount keeps its share (ADV-685-2: frozen, a discount
     * came to cost more than none).
     *
     * @param  array<string, string>  $discount
     * @param  list<string>  $nets
     */
    #[DataProvider('repriced')]
    public function test_the_cron_reprices_the_charge_and_the_discount_keeps_its_share(array $discount, array $nets, string $recorded): void
    {
        $service = $this->service($this->small, 10.00);
        $this->staffUpgrade($service, $discount);
        $upgrade = ServiceUpgrade::query()->sole();

        Carbon::setTestNow('2026-07-25 03:00:00');
        $this->artisan('app:cron-job');

        $lines = $this->lines($upgrade->invoice->fresh());
        $this->assertSame($nets, array_column($lines, 1));
        $this->assertSame($recorded, $upgrade->fresh()->discount, 'the record follows the line');
        $this->assertLessThanOrEqual(480, (int) round((float) $this->totalOf($upgrade->invoice->fresh()) * 100), 'never above no discount');
        $this->assertSame(Invoice::STATUS_UNPAID, $upgrade->invoice->fresh()->status);
    }

    public function test_a_later_downgrade_gives_back_only_what_the_discounted_upgrade_paid(): void
    {
        $this->storeSettings(['credits_on_downgrade' => true]);
        $credits = [];
        foreach ([null, '10'] as $percent) {
            $service = $this->service($this->small, 10.00);
            // Its month paid (12.40 with tax): what is left of it, and of the upgrade, caps what a downgrade gives back.
            $renewal = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID,
                'currency_code' => 'USD', 'due_at' => '2026-06-30']);
            $renewal->items()->create(['description' => 'Business10', 'price' => 10, 'quantity' => 1, 'reference_type' => Service::class,
                'reference_id' => $service->id, 'period_start' => '2026-06-30', 'period_end' => '2026-07-31']);
            DB::table('invoices')->where('id', $renewal->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => '2026-06-30']);
            PlaceUpgrade::handle($service, $this->large, [], ServiceUpgrade::SOURCE_STAFF, null,
                $percent === null ? null : DifferenceDiscount::PERCENT, $percent ?? '');
            $upgrade = ServiceUpgrade::query()->where('service_id', $service->id)->sole();
            (new RecordPaymentService)->handle($upgrade->invoice, null, $this->totalOf($upgrade->invoice), transactionId: 'pay-' . $service->id);
            $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);

            $back = PlaceUpgrade::handle($service->fresh(), $this->small, [], ServiceUpgrade::SOURCE_STAFF);
            $this->assertSame(PlaceUpgrade::APPLIED, $back->outcome);
            $credits[] = $back->creditCents;
        }

        $this->assertSame(128, $credits[0] - $credits[1], 'the 1.28 not paid is not given back');
    }

    // ------------------------------------------------------------------ the customer's flow

    public function test_the_customer_never_gets_a_discount(): void
    {
        $service = $this->service($this->small, 10.00);

        $this->expectException(InvalidArgumentException::class);
        PlaceUpgrade::handle($service, $this->large, [], ServiceUpgrade::SOURCE_CLIENT, null, DifferenceDiscount::WAIVE);
    }

    public function test_the_customers_upgrade_is_one_line_as_before(): void
    {
        $service = $this->service($this->small, 10.00);

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $this->large->id)->call('doUpgrade');

        $upgrade = ServiceUpgrade::query()->sole();
        $this->assertSame([['Upgrade Business10 to Business20', '10.32', '12.80', null]], $this->lines($upgrade->invoice));
        $this->assertNull($upgrade->discount);
    }

    public function test_no_discount_from_staff_is_one_line_as_before(): void
    {
        $service = $this->service($this->small, 10.00);

        $this->staffUpgrade($service, ['discount_mode' => 'none']);

        $upgrade = ServiceUpgrade::query()->sole();
        $this->assertSame([['Upgrade Business10 to Business20', '10.32', '12.80', null]], $this->lines($upgrade->invoice));
        $this->assertNull($upgrade->discount);
    }
}
