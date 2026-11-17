<?php

namespace Tests\Feature;

use App\Livewire\Client\Account;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\EmailLog;
use App\Models\User;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Audit 2026-11-18: the account's address (its primary contact's) gets the welcome mails with the services' passwords.
 * A member who may not manage the services never moves it, and the old address is told when it moves.
 */
class PrimaryEmailMoveTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->client = $owner->client();
        $this->client->primaryContact->forceFill(['country' => 'GR', 'email' => 'billing@example.test', 'email_preferences' => null])->save();
        EmailLog::query()->delete();
    }

    /** @param  list<string>|null  $permissions */
    private function member(?array $permissions): User
    {
        $member = User::factory()->withoutClient()->create(['email' => 'member@example.test']);
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $member->id, 'owner' => $permissions === null,
            'permissions' => $permissions, 'granted_at' => now()]);
        app(CurrentClient::class)->forget();
        $this->actingAs($member)->withSession($this->loginUser($member, [CurrentClient::SESSION_KEY => $this->client->id]));

        return $member;
    }

    public function test_a_member_who_may_see_every_kind_but_not_manage_services_cannot_move_it(): void
    {
        $this->member(['profile', 'emails', 'invoices', 'tickets', 'products', 'domains', 'affiliates']);

        Livewire::test(Account::class)->set('contact.email', 'member@example.test')->call('submit')->assertHasErrors(['contact.email']);

        $this->assertSame('billing@example.test', $this->client->primaryContact->fresh()->email);
    }

    public function test_one_who_may_moves_it_and_the_old_address_is_told(): void
    {
        $this->member(['profile', 'emails', 'invoices', 'tickets', 'products', 'domains', 'affiliates', 'manageproducts']);

        Livewire::test(Account::class)->set('contact.email', 'new-billing@example.test')->call('submit')->assertHasNoErrors();

        $this->assertSame('new-billing@example.test', $this->client->primaryContact->fresh()->email);
        $notice = EmailLog::query()->where('to', 'billing@example.test')->sole();
        $this->assertSame(__('account.notifications.contact_email_changed_subject'), $notice->subject);
        $this->assertStringContainsString('new-billing@example.test', (string) $notice->body);
    }

    public function test_nothing_is_told_when_it_stays(): void
    {
        $this->member(['profile', 'emails', 'invoices', 'tickets', 'products', 'domains', 'affiliates']);

        Livewire::test(Account::class)->set('contact.email', 'BILLING@example.test')->call('submit')->assertHasNoErrors();

        $this->assertSame(0, EmailLog::query()->where('to', 'billing@example.test')->count());
    }
}
