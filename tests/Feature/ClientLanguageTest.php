<?php

namespace Tests\Feature;

use App\Actions\Auth\Login;
use App\Helpers\NotificationHelper;
use App\Livewire\Client\Account;
use App\Livewire\Components\LocaleSwitch;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A customer's language (docs/accounts.md): the account's for its mails and PDFs, set by the customer or staff; a login's
 * for its pages, kept from the language switch and set at login. Only an allowed one; else the instance's.
 */
class ClientLanguageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.app_language' => 'el', 'settings.allowed_languages' => ['el', 'en'], 'settings.default_currency' => 'EUR']);
        $this->user = User::factory()->create();
    }

    public function test_mails_go_in_the_accounts_language_else_the_instances(): void
    {
        $client = $this->user->client();
        $this->assertSame('el', NotificationHelper::localeFor($client));

        $client->forceFill(['language' => 'en'])->save();
        $this->assertSame('en', NotificationHelper::localeFor($client->fresh()));

        $client->forceFill(['language' => 'da'])->save();
        $this->assertSame('el', NotificationHelper::localeFor($client->fresh()), 'not an allowed one: the instance\'s');
    }

    public function test_the_customer_sets_the_accounts_language(): void
    {
        $this->actingAs($this->user)->withSession($this->loginUser($this->user, [CurrentClient::SESSION_KEY => $this->user->client()->id]));

        Livewire::test(Account::class)->set('language', 'en')->call('submit')->assertHasNoErrors();
        $this->assertSame('en', $this->user->client()->fresh()->language);

        Livewire::test(Account::class)->set('language', 'xx')->call('submit')->assertHasErrors('language');
        Livewire::test(Account::class)->set('language', '')->call('submit')->assertHasNoErrors();
        $this->assertNull($this->user->client()->fresh()->language);
    }

    public function test_the_switch_is_kept_and_the_next_login_opens_in_it(): void
    {
        $this->actingAs($this->user)->withSession($this->loginUser($this->user, [CurrentClient::SESSION_KEY => $this->user->client()->id]));
        Livewire::test(LocaleSwitch::class)->set('currentLocale', 'en');
        $this->assertSame('en', $this->user->fresh()->language);

        session()->forget('locale');
        app(Login::class)->execute($this->user->fresh());
        $this->assertSame('en', session('locale'));
    }
}
