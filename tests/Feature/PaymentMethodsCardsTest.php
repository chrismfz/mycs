<?php

namespace Tests\Feature;

use App\Livewire\Client\PaymentMethods;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Gateway;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use MyCS\Extensions\Gateways\CardsSave\CardsSave;
use Tests\TestCase;

/**
 * «Payment methods» by provider (docs/pay-flow-ux.md, PR 6a): a card per gateway with its saved methods, a radio for the
 * account's automatic payments (makeDefault and its guards), «Add» where the gateway saves methods, and a line where it
 * saves none.
 */
class PaymentMethodsCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $saves;

    private Gateway $savesNothing;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(CardsSave::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\CardsSave; class CardsSave { public $explicitConfig; public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }
                public function createBillingAgreement($client) { return "https://cards-save.test/setup/" . $client->id; } }');
            eval('namespace MyCS\\Extensions\\Gateways\\CardsNoSave; class CardsNoSave { public $explicitConfig; public function __construct($config = []) {} }');
            eval('namespace MyCS\\Extensions\\Gateways\\CardsView; class CardsView { public $explicitConfig; public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }
                public function createBillingAgreement($client) { return new \\Illuminate\\Support\\HtmlString("<div>CARDS VIEW FORM</div>"); } }');
        }
        $this->saves = Gateway::query()->create(['extension' => 'CardsSave', 'name' => 'Cards Save', 'type' => 'gateway', 'enabled' => true]);
        $this->savesNothing = Gateway::query()->create(['extension' => 'CardsNoSave', 'name' => 'Wire Transfer', 'type' => 'gateway', 'enabled' => true]);
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
    }

    private function method(string $name, ?Gateway $gateway = null): BillingAgreement
    {
        return BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => ($gateway ?? $this->saves)->id,
            'name' => $name, 'external_reference' => $name, 'type' => 'visa']);
    }

    public function test_a_card_per_provider_with_its_methods_add_where_it_saves_and_a_note_where_it_saves_none(): void
    {
        $first = $this->method('Visa 4242');
        $this->method('Visa 1111');

        $page = $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertSeeInOrder(['Cards Save', 'Visa 4242', 'Visa 1111', __('account.add_payment_method'), 'Wire Transfer', __('account.methods_cards.saves_nothing')])
            ->assertSee(__('account.default_payment_method'))->assertSee(__('account.make_default_payment_method'))
            ->assertSee('addMethod(' . $this->saves->id . ')', false)->assertDontSee('addMethod(' . $this->savesNothing->id . ')', false);

        $this->assertSame($first->ulid, $page->get('automaticMethod'), 'the radio of the method that pays renewals is on');
        $providers = $page->viewData('providers');
        $this->assertSame([$this->saves->id, $this->savesNothing->id], array_map(fn ($card) => $card['gateway']->id, $providers));
        $this->assertSame([true, false], array_column($providers, 'canAdd'));
    }

    public function test_a_provider_that_saves_methods_with_none_saved_says_so_and_offers_add(): void
    {
        $this->method('Other card', $this->savesNothing); // a method, so the switch shows; its provider saves none any more

        $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertSeeInOrder(['Wire Transfer', 'Other card', 'Cards Save', __('account.methods_cards.none_saved'), __('account.add_payment_method')])
            ->assertSee(__('account.auto_charge'));
    }

    public function test_the_radio_makes_a_method_the_accounts_automatic_one(): void
    {
        $this->method('Visa 4242');
        $second = $this->method('Visa 1111');

        $page = $this->livewireAs($this->user)->test(PaymentMethods::class)->set('automaticMethod', $second->ulid);

        $this->assertSame($second->id, (int) $this->client->fresh()->default_billing_agreement_id);
        $this->assertSame($second->ulid, $page->get('automaticMethod'));
    }

    public function test_a_refused_radio_shows_the_method_the_account_still_has(): void
    {
        $first = $this->method('Visa 4242');
        $off = Gateway::query()->create(['extension' => 'CardsSave', 'name' => 'Off Gateway', 'type' => 'gateway', 'enabled' => false]);
        $disabled = $this->method('Old card', $off);
        $other = Client::factory()->ownedBy()->create();
        $foreign = BillingAgreement::query()->create(['client_id' => $other->id, 'gateway_id' => $this->saves->id, 'name' => 'Theirs',
            'external_reference' => 'theirs', 'type' => 'visa']);

        $page = $this->livewireAs($this->user)->test(PaymentMethods::class);
        foreach ([$disabled->ulid, $foreign->ulid, 'no-such-ulid', ''] as $ulid) {
            $page->set('automaticMethod', $ulid);
            $this->assertSame($first->id, (int) $this->client->fresh()->default_billing_agreement_id, 'refused: ' . $ulid);
            $this->assertSame($first->ulid, $page->get('automaticMethod'), 'the radios show the account\'s method: ' . $ulid);
        }
        $this->assertSame($other->fresh()->default_billing_agreement_id, $foreign->id, 'the other account keeps its own');
    }

    public function test_a_disabled_gateways_method_has_no_radio_and_says_it_is_unavailable(): void
    {
        $this->method('Visa 4242');
        $off = Gateway::query()->create(['extension' => 'CardsSave', 'name' => 'Off Gateway', 'type' => 'gateway', 'enabled' => false]);
        $disabled = $this->method('Old card', $off);

        $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertSeeInOrder(['Off Gateway', 'Old card', __('account.payment_method_unavailable')])
            ->assertDontSee('value="' . $disabled->ulid . '"', false)
            ->assertDontSee('addMethod(' . $off->id . ')', false);
    }

    public function test_staff_without_the_money_permission_change_no_radio(): void
    {
        $first = $this->method('Visa 4242');
        $second = $this->method('Visa 1111');
        session(['impersonating' => $this->user->id, CurrentClient::SESSION_KEY => $this->client->id, CurrentClient::IMPERSONATING_KEY => $this->client->id]);
        Auth::guard('staff')->setUser(Staff::factory()->withPermissions(['admin.users.impersonate'])->create());
        Auth::guard('web')->setUser($this->user);
        app(CurrentClient::class)->forget();

        $page = Livewire::test(PaymentMethods::class)->set('automaticMethod', $second->ulid);

        $this->assertSame($first->id, (int) $this->client->fresh()->default_billing_agreement_id, 'SA-5');
        $this->assertSame($first->ulid, $page->get('automaticMethod'));
    }

    public function test_a_page_opened_for_one_account_marks_no_method_of_another(): void
    {
        $first = $this->method('Visa 4242');
        $b = Client::factory()->ownedBy()->create();
        ClientUser::query()->create(['client_id' => $b->id, 'user_id' => $this->user->id, 'granted_at' => now()]);
        $theirs = BillingAgreement::query()->create(['client_id' => $b->id, 'gateway_id' => $this->saves->id, 'name' => 'B card',
            'external_reference' => 'b', 'type' => 'visa']);
        $this->actingAs($this->user);
        $page = $this->livewireAs($this->user)->test(PaymentMethods::class);

        $this->assertTrue(app(CurrentClient::class)->switchTo($b->id)); // another tab switched account
        $page->set('automaticMethod', $theirs->ulid);

        $this->assertSame($theirs->id, (int) $b->fresh()->default_billing_agreement_id, 'unchanged: it was already B\'s first');
        $this->assertSame($first->id, (int) $this->client->fresh()->default_billing_agreement_id);
        $this->assertSame($first->ulid, $page->get('automaticMethod'), 'the page\'s account\'s, not the other\'s');
    }

    public function test_add_in_a_card_starts_that_gateways_setup_and_only_one_that_saves(): void
    {
        $page = $this->livewireAs($this->user)->test(PaymentMethods::class);

        foreach ([$this->savesNothing->id, 999999, 'x', '1; drop', null] as $id) {
            $page->call('addMethod', $id)->assertNoRedirect();
        }
        $page->call('addMethod', $this->saves->id)->assertRedirect('https://cards-save.test/setup/' . $this->client->id);
    }

    public function test_staff_without_the_money_permission_add_no_method(): void
    {
        session(['impersonating' => $this->user->id, CurrentClient::SESSION_KEY => $this->client->id, CurrentClient::IMPERSONATING_KEY => $this->client->id]);
        Auth::guard('staff')->setUser(Staff::factory()->withPermissions(['admin.users.impersonate'])->create());
        Auth::guard('web')->setUser($this->user);
        app(CurrentClient::class)->forget();

        Livewire::test(PaymentMethods::class)->call('addMethod', $this->saves->id)->assertNoRedirect();
    }

    public function test_a_method_whose_gateway_was_deleted_is_under_other_and_can_be_removed(): void
    {
        $gone = Gateway::query()->create(['extension' => 'CardsSave', 'name' => 'Gone Gateway', 'type' => 'gateway', 'enabled' => true]);
        $orphan = $this->method('Orphan card', $gone);
        $gone->delete();

        $page = $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertSeeInOrder([__('account.methods_cards.other'), 'Orphan card', __('account.payment_method_unavailable')])
            ->assertDontSee('value="' . $orphan->ulid . '"', false);

        $page->call('removePaymentMethod', $orphan->ulid);
        $this->assertNull(BillingAgreement::query()->find($orphan->id));
    }

    public function test_greek_words(): void
    {
        app()->setLocale('el');
        $this->method('Visa 4242');

        $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertSee('Αυτόματη πληρωμή ανανεώσεων · Ενεργή')
            ->assertSee('Χρησιμοποιείται για τις αυτόματες πληρωμές')
            ->assertSee('Δεν αποθηκεύει τρόπους πληρωμής');
    }

    /** Review of 6a: the default changed meanwhile (an invoice paid with «save», staff); the next render shows it. */
    public function test_the_radios_follow_the_account_on_any_render(): void
    {
        $this->method('Visa 4242');
        $second = $this->method('Visa 1111');
        $page = $this->livewireAs($this->user)->test(PaymentMethods::class);

        $this->client->update(['default_billing_agreement_id' => $second->id]); // another tab
        $page->set('autoCharge', false); // any other request on the page

        $this->assertSame($second->ulid, $page->get('automaticMethod'));
    }

    /** Review of 6a: a gateway whose setup is a form (Stripe) shows it from the card's «Add» and from «?setup=1». */
    public function test_a_gateways_setup_form_shows_from_the_card_and_from_the_chooser(): void
    {
        $view = Gateway::query()->create(['extension' => 'CardsView', 'name' => 'Cards View', 'type' => 'gateway', 'enabled' => true]);

        $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertDontSee('CARDS VIEW FORM')
            ->call('addMethod', $view->id)->assertSee('CARDS VIEW FORM')->assertSee(__('account.setup_payment_method'))
            ->call('cancelSetup')->assertDontSee('CARDS VIEW FORM');

        $this->livewireAs($this->user)->withQueryParams(['setup' => 1])->test(PaymentMethods::class)
            ->assertSee(__('account.input.payment_gateway'))
            ->set('gateway', $view->id)->call('createBillingAgreement')->assertSee('CARDS VIEW FORM');
    }

    public function test_a_radio_is_named_by_its_method_and_waits_for_its_pick(): void
    {
        $this->method('Visa 4242');

        $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertSee('<span class="sr-only">: Visa 4242</span>', false)
            ->assertSee('wire:target="automaticMethod"', false);
    }
}
