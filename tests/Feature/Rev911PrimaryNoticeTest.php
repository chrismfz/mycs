<?php

namespace Tests\Feature;

use App\Livewire\Client\Account;
use App\Models\EmailLog;
use App\Models\User;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Review of #911: when the account's address moves, its old address is told. The notice is skipped when the old address
 * is the login's (to not tell it twice), but the login's notice goes only when the login's email changed too.
 */
class Rev911PrimaryNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_whose_login_is_the_accounts_address_moving_only_the_account_address_is_told(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $client = $owner->client();
        // The usual case: the account's address is the owner's login (a sign-up, an import).
        $client->primaryContact->forceFill(['country' => 'GR', 'email' => 'owner@example.test', 'email_preferences' => null])->save();
        EmailLog::query()->delete();
        app(CurrentClient::class)->forget();
        $this->actingAs($owner)->withSession($this->loginUser($owner, [CurrentClient::SESSION_KEY => $client->id]));

        Livewire::test(Account::class)->set('contact.email', 'elsewhere@example.test')->call('submit')->assertHasNoErrors();

        $this->assertSame('elsewhere@example.test', $client->primaryContact->fresh()->email);
        $this->assertSame(1, EmailLog::query()->where('to', 'owner@example.test')
            ->where('subject', __('account.notifications.contact_email_changed_subject'))->count(), 'the old address is told');
    }
}
