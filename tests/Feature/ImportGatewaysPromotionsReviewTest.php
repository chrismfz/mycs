<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Code review of e4d3dac (importer: WHMCS gateways and promotions): a promotion WHMCS would no longer give, or would
 * give to less, must not come over open in MyCS; one WHMCS still gives must not close early.
 */
class ImportGatewaysPromotionsReviewTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        User::factory()->create();
        $this->productId = $this->createProduct()->product->id;
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblpromotions (id INTEGER PRIMARY KEY, code TEXT, type TEXT, recurring INT, value REAL, cycles TEXT, appliesto TEXT,
            requires TEXT, requiresexisting INT, startdate TEXT, expirationdate TEXT, maxuses INT, uses INT, lifetimepromo INT, applyonce INT,
            newsignups INT, existingclient INT, onceperclient INT, recurfor INT, upgrades INT, notes TEXT)');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $columns */
    private function promotion(int $id, string $code, array $columns = []): void
    {
        $row = array_merge(['id' => $id, 'code' => $code, 'type' => 'Percentage', 'recurring' => 0, 'value' => 10, 'cycles' => '', 'appliesto' => (string) $this->productId,
            'requires' => '', 'requiresexisting' => 0, 'startdate' => '0000-00-00', 'expirationdate' => '0000-00-00', 'maxuses' => 0, 'uses' => 0, 'lifetimepromo' => 0,
            'applyonce' => 0, 'newsignups' => 0, 'existingclient' => 0, 'onceperclient' => 0, 'recurfor' => 0, 'upgrades' => 0, 'notes' => ''], $columns);
        $statement = $this->whmcs->prepare('INSERT INTO tblpromotions (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')');
        $statement->execute(array_values($row));
    }

    private function importPromotions(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importPromotions'))->invoke($command);
    }

    /** What the cart refuses (Cart::applyCoupon): expired, or its uses (services with it) reached. */
    private function usable(Coupon $coupon): bool
    {
        if ($coupon->expires_at !== null && $coupon->expires_at->lessThanOrEqualTo(now())) {
            return false;
        }

        return !($coupon->max_uses && $coupon->services()->count() >= $coupon->max_uses);
    }

    /**
     * F1: WHMCS counts a promotion's uses in tblpromotions.uses (per order, including domains, addons and services
     * deleted since). MyCS counts the services that carry the coupon. A promotion used up in WHMCS (uses >= maxuses)
     * whose uses are not all imported services comes over open with all its uses back.
     */
    public function test_a_promotion_used_up_in_whmcs_is_not_reopened(): void
    {
        $this->promotion(9, 'LAUNCH', ['maxuses' => 1, 'uses' => 1]);

        $this->importPromotions();

        $this->assertFalse($this->usable(Coupon::query()->findOrFail(9)), 'WHMCS refuses it: max uses reached');
    }

    /**
     * F2: tblpromotions.expirationdate is a date and WHMCS refuses a promotion only when it is before today
     * (`expirationdate < today`): it is good all of its last day. Imported as that day's 00:00, MyCS refuses it all day.
     */
    public function test_a_promotion_is_good_all_of_its_last_day(): void
    {
        $this->promotion(10, 'LASTDAY', ['expirationdate' => '2026-10-20']);

        $this->importPromotions();

        $this->assertTrue($this->usable(Coupon::query()->findOrFail(10)), 'WHMCS still accepts it today');
    }

    /**
     * F3: WHMCS discounts only the items in a promotion's «Applies To» (in_array of tblpromotions.appliesto): with
     * nothing selected it discounts nothing. A MyCS coupon without products covers every product (Coupon::covers).
     */
    public function test_a_promotion_applying_to_nothing_does_not_cover_every_product(): void
    {
        $this->promotion(11, 'EMPTY', ['appliesto' => '']);

        $this->importPromotions();

        $coupon = Coupon::query()->findOrFail(11);
        $this->assertFalse($this->usable($coupon) && $coupon->covers($this->productId), 'WHMCS gives it on no product');
    }
}
