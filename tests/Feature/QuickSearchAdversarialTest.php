<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Livewire\Components\QuickSearch;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #232 (quick search): another account's records, drafts, grants, crafted input and tampering.
 * test_holds_… pin good behaviour; the others fail on the PR as it stands.
 */
class QuickSearchAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $user, ?Client $client = null, array $session = []): void
    {
        $this->actingAs($user)->withSession($this->loginUser($user, array_merge([CurrentClient::SESSION_KEY => ($client ?? $user->client())->id], $session)));
    }

    /** @return list<string> */
    private function search(string $q): array
    {
        return array_column(Livewire::test(QuickSearch::class)->set('q', $q)->instance()->results(), 'title');
    }

    private function ticket(Client $client, string $subject): Ticket
    {
        return Ticket::factory()->create(['client_id' => $client->id, 'subject' => $subject, 'status' => 'open']);
    }

    private function invoice(Client $client, string $number, string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID]);
        Invoice::query()->whereKey($invoice->id)->update(['number' => $number, 'status' => $status]);

        return $invoice->fresh();
    }

    private function domain(Client $client, string $sld): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);

        return Domain::factory()->create(['client_id' => $client->id, 'sld' => $sld, 'tld' => 'gr', 'fqdn' => "{$sld}.gr",
            'domain_tld_id' => $tld->id, 'status' => DomainStatus::Active]);
    }

    // ---- isolation ----------------------------------------------------------------------------------------------------

    public function test_holds_another_accounts_ticket_or_invoice_by_hash_id_is_not_found(): void
    {
        $user = User::factory()->create();
        $theirs = User::factory()->create()->client();
        $ticket = $this->ticket($theirs, 'Their secret');
        $invoice = $this->invoice($theirs, 'THEIRS-1');
        $this->as($user);

        $this->assertSame([], $this->search('#' . $ticket->id));
        $this->assertSame([], $this->search('#' . $invoice->id));
        $this->assertSame([], $this->search((string) $ticket->id . ' '));
    }

    public function test_holds_a_draft_is_not_found_by_its_id_either(): void
    {
        $user = User::factory()->create();
        $draft = $this->invoice($user->client(), 'D-1', Invoice::STATUS_DRAFT);
        $this->as($user);

        $this->assertSame([], $this->search('#' . $draft->id));
    }

    public function test_holds_a_login_without_any_account_finds_nothing_and_does_not_fail(): void
    {
        $this->ticket(User::factory()->create()->client(), 'Shop down');
        $nobody = User::factory()->withoutClient()->create();
        $this->actingAs($nobody)->withSession($this->loginUser($nobody));

        $this->assertSame([], $this->search('shop'));
    }

    public function test_holds_a_guest_finds_nothing(): void
    {
        $this->ticket(User::factory()->create()->client(), 'Shop down');

        $this->assertSame([], $this->search('shop'));
    }

    public function test_holds_a_revoked_member_finds_nothing_of_the_account(): void
    {
        $client = User::factory()->create()->client();
        $this->ticket($client, 'Shop down');
        $this->domain($client, 'shopx');
        $member = User::factory()->withoutClient()->create();
        $grant = ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $member->id, 'granted_at' => now()]);
        $client->revoke($grant);
        $this->as($member, $client);

        $this->assertSame([], $this->search('shop'));
    }

    public function test_holds_a_grant_revoked_after_the_component_is_mounted_finds_nothing_on_the_next_request(): void
    {
        $client = User::factory()->create()->client();
        $this->ticket($client, 'Shop down');
        $member = User::factory()->withoutClient()->create();
        $grant = ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $member->id, 'granted_at' => now()]);
        $this->as($member, $client);

        $component = Livewire::test(QuickSearch::class)->set('q', 'shop');
        $this->assertNotSame([], $component->instance()->results());

        $client->revoke($grant);
        app(CurrentClient::class)->forget(); // a new request: CurrentClient is scoped
        $component->set('q', 'shop ');
        $this->assertSame([], $component->instance()->results());
    }

    public function test_holds_switching_accounts_with_the_component_mounted_searches_the_new_one(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create()->client();
        ClientUser::query()->create(['client_id' => $other->id, 'user_id' => $user->id, 'granted_at' => now()]);
        $this->ticket($user->client(), 'Shop mine');
        $this->ticket($other, 'Shop other');
        $this->as($user);

        $component = Livewire::test(QuickSearch::class)->set('q', 'shop');
        $this->assertSame(['Shop mine'], array_map(fn ($t) => explode(' · ', $t, 2)[1], array_column($component->instance()->results(), 'title')));

        app(CurrentClient::class)->switchTo($other->id);
        $component->set('q', 'shop ');
        $this->assertSame(['Shop other'], array_map(fn ($t) => explode(' · ', $t, 2)[1], array_column($component->instance()->results(), 'title')));
    }

    public function test_holds_staff_logged_in_as_a_client_see_that_account_only(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create()->client();
        ClientUser::query()->create(['client_id' => $other->id, 'user_id' => $customer->id, 'granted_at' => now()]);
        $this->ticket($customer->client(), 'Shop mine');
        $this->ticket($other, 'Shop other');
        $this->ticket(User::factory()->create()->client(), 'Shop stranger');
        $staff = Staff::factory()->withPermissions(['admin.users.viewAny', 'admin.users.impersonate'])->create();

        // The customer's session chose $other; «Login as client» takes the customer's default account, not that.
        $this->actingAs($customer)->withSession($this->loginUser($customer, ['impersonating' => $customer->id, CurrentClient::SESSION_KEY => $other->id]));
        $this->actingAs($staff, 'staff');

        $found = $this->search('shop');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('Shop mine', $found[0]);
    }

    public function test_holds_tickets_disabled_finds_no_ticket_even_by_id(): void
    {
        config(['settings.tickets_disabled' => true]);
        $user = User::factory()->create();
        $ticket = $this->ticket($user->client(), 'Shop down');
        $this->as($user);

        $this->assertSame([], $this->search('shop'));
        $this->assertSame([], $this->search('#' . $ticket->id));
    }

    public function test_holds_a_member_without_invoices_or_tickets_does_not_find_them_by_id(): void
    {
        $client = User::factory()->create()->client();
        $ticket = $this->ticket($client, 'Shop down');
        $invoice = $this->invoice($client, 'X-1');
        $member = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $member->id, 'permissions' => ['domains'], 'granted_at' => now()]);
        $this->as($member, $client);

        $this->assertSame([], $this->search('#' . $ticket->id));
        $this->assertSame([], $this->search('#' . $invoice->id));
    }

    // ---- crafted input ------------------------------------------------------------------------------------------------

    public function test_holds_quotes_backslashes_bangs_and_wildcards_are_matched_literally(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        foreach (["It's 50% off_now!", 'C:\\path\\to', 'a!%b', 'plain one', 'x"y; drop table tickets; --'] as $subject) {
            $this->ticket($client, $subject);
        }
        $this->as($user);

        $titles = fn (string $q) => array_map(fn ($t) => explode(' · ', $t, 2)[1], $this->search($q));
        $this->assertSame(["It's 50% off_now!"], $titles("'s 50%"));
        $this->assertSame(["It's 50% off_now!"], $titles('off_now!'));
        $this->assertSame(['C:\\path\\to'], $titles('\\path\\'));
        $this->assertSame(['a!%b'], $titles('!%'));
        $this->assertSame([], $titles('!!'));
        $this->assertSame([], $titles('%_'));
        $this->assertSame(['x"y; drop table tickets; --'], $titles('"y; drop'));
        $this->assertSame([], $titles('\\%'));
        $this->assertSame([], $titles("' or 1=1 --"));
        $this->assertSame(5, Ticket::query()->count());
    }

    public function test_holds_odd_input_never_fails(): void
    {
        $user = User::factory()->create();
        $this->ticket($user->client(), 'Πρόβλημα με το email');
        $this->invoice($user->client(), '2026-0001');
        $this->as($user);

        foreach (["\0\0", "a\0b", '##', '#', '# 1', '#-1', '#1.5', '#99999999999999999999999', str_repeat('9', 100),
            str_repeat('x', 100), str_repeat('x', 101), str_repeat('ü', 100), str_repeat('ab', 100000), "\u{00A0}\u{00A0}",
            '😀😀', "\u{202E}gnp", '0x1F', '1e3', '-0', ' 00 ', "\t\n", 'πρόβλημα', 'email'] as $q) {
            try {
                $this->search($q);
            } catch (Throwable $e) {
                $this->fail('«' . substr(bin2hex($q), 0, 40) . '» failed: ' . $e->getMessage());
            }
        }
        // (Greek is case-folded by MariaDB's lower(), not SQLite's, so the suite cannot pin a Greek case-insensitive match.)
        $this->assertCount(1, $this->search('το email'));
        $this->assertSame([], $this->search('#99999999999999999999999'));
    }

    /** The component is rendered too (as a Livewire update would), with each odd input, never a 500. */
    public function test_holds_the_rendered_list_never_fails_on_odd_input(): void
    {
        $user = User::factory()->create();
        $this->ticket($user->client(), '<script>alert(1)</script>');
        $this->as($user);

        Livewire::test(QuickSearch::class)->set('q', '<script')->assertOk()->assertDontSeeHtml('<script>alert(1)</script>')
            ->set('q', "\0\0")->assertOk()
            ->set('q', str_repeat('<b>', 50))->assertOk()->assertDontSeeHtml('<b><b>');
    }

    /** Livewire property tampering with a wrong type: Livewire refuses it (419), not a 500. */
    public function test_holds_an_array_sent_for_q_is_refused_not_a_500(): void
    {
        $user = User::factory()->create();
        $this->as($user);

        foreach ([['shop'], ['a' => ['b' => 'c']]] as $tampered) {
            Livewire::test(QuickSearch::class)->update([], ['q' => $tampered])->assertStatus(419);
        }
        foreach ([12345, 1.5, true] as $scalar) { // coerced to a string, a search like any other
            Livewire::test(QuickSearch::class)->update([], ['q' => $scalar])->assertStatus(200);
        }
    }

    /**
     * {"updates": {"q": null}} (an edited payload): Livewire unsets the typed `string $q` on the TypeError, and the
     * render then reads an uninitialised property: an exception (a 500 and an error-log entry per request) instead of
     * «no search».
     */
    public function test_null_sent_for_q_is_no_search_not_a_500(): void
    {
        $user = User::factory()->create();
        $this->as($user);

        try {
            $component = Livewire::test(QuickSearch::class)->update([], ['q' => null]);
        } catch (Throwable $e) {
            $this->fail('q = null failed: ' . get_class($e) . ': ' . $e->getMessage());
        }
        $component->assertStatus(200);
        $this->assertSame([], $component->instance()->results());
    }

    // ---- what is shown ------------------------------------------------------------------------------------------------

    public function test_holds_a_service_without_label_or_domain_shows_its_product_name(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'label' => null, 'domain' => null]);
        $this->as($user);

        $found = Livewire::test(QuickSearch::class)->set('q', '#' . $service->id)->instance()->results();
        $this->assertSame([$product->product->name . ' #' . $service->id], array_column(array_filter($found, fn ($r) => $r['kind'] === 'service'), 'title'));
        $this->assertNotSame('', $found[0]['title']);
    }

    /** An invoice imported from WHMCS without a created_at: the portal may still find it, but not with a 500. */
    public function test_an_invoice_without_created_at_does_not_break_the_search(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoice($user->client(), 'IMPORTED-9');
        Invoice::query()->whereKey($invoice->id)->update(['created_at' => null]);
        $this->as($user);

        try {
            $found = $this->search('imported');
        } catch (Throwable $e) {
            $this->fail('a null created_at failed the whole search: ' . $e->getMessage());
        }
        $this->assertCount(1, $found);
    }

    /**
     * A Greek IDN (.gr is full of them): the fqdn is kept as punycode, so a customer typing the name as they know it
     * («ελλάδα.gr») finds nothing.
     */
    public function test_a_greek_idn_is_found_by_its_greek_name(): void
    {
        $user = User::factory()->create();
        $this->domain($user->client(), 'xn--hxakic4aa');
        $this->as($user);

        // Author's note (findings of #232): found by punycode too, and shown by its Greek name, as this test's fix suggests.
        $this->assertSame(['ελλάδα.gr'], $this->search('xn--hxakic4aa'), 'by punycode it is found');
        $this->assertSame(['ελλάδα.gr'], $this->search('ελλάδα.gr'));
    }

    /** Per kind at most PER_KIND, so a common term is cheap; the newest first. */
    public function test_holds_at_most_five_of_a_kind(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 12; $i++) {
            $this->ticket($user->client(), "Shop {$i}");
        }
        $this->as($user);

        $found = $this->search('shop');
        $this->assertCount(QuickSearch::PER_KIND, $found);
        $this->assertStringContainsString('Shop 11', $found[0]);
    }
}
