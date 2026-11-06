<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;

/**
 * A blank subscription id is none (Service::subscriptionId, 2026-11-03): MariaDB compares VARCHARs with trailing spaces
 * padded (`' ' = ''`), SQLite and PHP do not, so a stored «' '» made the SQL checks (AutoCharge::due,
 * Invoice::billsSubscription) and the PHP ones (`(string) $service->subscription_id !== ''`) disagree, and only on
 * MariaDB. Never stored, they agree on every database.
 */
class BlankSubscriptionIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_blank_subscription_id_is_stored_as_none_and_every_check_agrees(): void
    {
        $client = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id, 'subscription_id' => null]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        // Spaces, a tab, and what MariaDB's collation holds equal to '' (a no-break space, a zero-width space, a control
        // character: review of #608).
        foreach ([' ', '   ', "\t", '', null, "\u{00A0}", "\u{200B}", "\u{FEFF}", "\x01"] as $blank) {
            $service->update(['subscription_id' => $blank]);
            $this->assertNull(DB::table('services')->where('id', $service->id)->value('subscription_id'), 'stored as none: ' . var_export($blank, true));
            $this->assertSame('', (string) $service->fresh()->subscription_id, 'PHP: none');
            $this->assertFalse($invoice->fresh()->billsSubscription(), 'SQL: none');
        }
        foreach (['sub_1', 'I-LEAD', '0'] as $id) {
            $service->update(['subscription_id' => $id]);
            $this->assertSame($id, $service->fresh()->subscription_id);
            $this->assertTrue($invoice->fresh()->billsSubscription(), 'SQL: a subscription ' . $id);
        }
    }

    /** Review of #608: PayPal's marker kept with no id changes no subscription (it summed every service without one). */
    public function test_paypal_changes_no_subscription_of_a_service_without_an_id(): void
    {
        Http::fake();
        $client = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id, 'subscription_id' => ' ']);
        $service->properties()->create(['key' => 'has_paypal_subscription', 'value' => '1']);

        $this->assertFalse((new PayPal(['test_mode' => true, 'client_id' => 'id', 'client_secret' => 'secret']))->updateSubscription($service->fresh()));
        Http::assertNothingSent();
    }

    public function test_the_migration_clears_a_blank_id_stored_before(): void
    {
        $client = User::factory()->create()->client();
        $blank = Service::factory()->create(['client_id' => $client->id]);
        $real = Service::factory()->create(['client_id' => $client->id, 'subscription_id' => 'sub_9']);
        DB::table('services')->where('id', $blank->id)->update(['subscription_id' => '  ']);

        (require database_path('migrations/2027_02_02_000000_blank_subscription_ids_are_none.php'))->up();

        $this->assertNull(DB::table('services')->where('id', $blank->id)->value('subscription_id'));
        $this->assertSame('sub_9', DB::table('services')->where('id', $real->id)->value('subscription_id'));
    }
}
