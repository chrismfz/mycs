<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Servers\CPanel\CPanel;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #315 (WHM resellers). The owner's worst case: a reseller's customers' sites deleted, or the
 * reseller itself removed, without staff typing its username. Each FAILING test is a finding; the passing ones pin
 * what held.
 */
class CPanelResellerAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private Product $product;

    private Service $service;

    /** @var list<string> accounts the reseller owns, other than its own */
    private array $owned = [];

    /** @var string|null the owner string WHM reports (null: the username searched for) */
    private ?string $ownerAs = null;

    /** @var list<string> WHM's resellers */
    private array $resellers = [];

    /** @var array<string, mixed>|null listaccts' body instead of the usual one */
    private ?array $listacctsBody = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        $this->server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $this->product = $product->product;
        $this->product->update(['server_id' => $this->server->id]);
        foreach (['package' => 'Business10', 'reseller' => '1', 'reseller_acl' => 'resellers', 'reseller_owns_itself' => '1'] as $key => $value) {
            $this->product->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $this->product->id, 'server_id' => $this->server->id, 'status' => 'active', 'expires_at' => now()->addMonth(),
            'currency_code' => 'USD', 'price' => 10.00, 'domain' => 'reseller.test']);
        Http::fake(fn (Request $request) => $this->whm($request));
    }

    private function whm(Request $request)
    {
        $call = (string) parse_url($request->url(), PHP_URL_PATH);
        $ok = fn (array $data = []) => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => $data]);

        return match (true) {
            str_ends_with($call, '/listpkgs') => Http::response(['package' => [['name' => 'Business10']]]),
            str_ends_with($call, '/listresellers') => $ok(['reseller' => $this->resellers]),
            str_ends_with($call, '/listaccts') => $this->listacctsBody !== null ? Http::response($this->listacctsBody)
                : $ok(['acct' => array_map(fn ($u) => ['user' => $u, 'owner' => $this->ownerAs ?? (string) $request['search']], $this->owned)]),
            // WHM refuses a reseller API for a user that is not a reseller, or for a reseller's own login.
            str_ends_with($call, '/suspendreseller'), str_ends_with($call, '/unsuspendreseller') => in_array((string) $request['user'], $this->resellers, true)
                && $this->server->settings()->where('key', 'username')->value('value') === 'root'
                ? $ok() : Http::response(['metadata' => ['result' => 0, 'reason' => 'not a reseller, or access denied']]),
            default => $ok(),
        };
    }

    private function sent(string $call): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/' . $call))->values()->all();
    }

    private function account(string $username = 'resel1', bool $flagged = true): void
    {
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => $username]);
        if ($flagged) {
            $this->service->properties()->create(['key' => CPanel::RESELLER, 'name' => 'WHM reseller', 'value' => '1']);
        }
        $this->resellers = [strtolower($username)];
    }

    private function deletedNothing(): void
    {
        $this->assertSame([], [...$this->sent('terminatereseller'), ...$this->sent('removeacct')], 'the reseller (or its accounts) went without staff typing its username');
    }

    private function cronTerminates(): void
    {
        $this->service->update(['status' => Service::STATUS_TERMINATED]);
        try {
            (new TerminateJob($this->service->fresh(), false))->handle();
        } catch (Throwable) {
            // refused: what we want
        }
    }

    /**
     * A1. myip's 22 resellers come from WHMCS: the importer sets no «reseller» on the product nor cpanel_reseller on the
     * service, so until staff tick the product the terminate guard never asks WHM what the account owns, and a cron
     * removeacct's a reseller with customers. The comment says «what WHM has»; the code asks the product.
     */
    public function test_a1_an_imported_reseller_on_an_unflagged_product_is_not_removed_by_a_cron(): void
    {
        $this->product->settings()->where('key', 'reseller')->update(['value' => '0']);
        $this->account(flagged: false);
        $this->owned = ['cust1', 'cust2'];

        $this->cronTerminates();

        $this->deletedNothing();
    }

    /** A2. listaccts answered «OK» with no acct list (another shape, a WHM change): read as «owns nothing» and removed. */
    public function test_a2_a_listing_of_an_unexpected_shape_fails_closed(): void
    {
        $this->account();
        $this->listacctsBody = ['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['accounts' => [['user' => 'cust1', 'owner' => 'resel1']]]];

        $this->cronTerminates();

        $this->deletedNothing();
    }

    /** A3. An imported username in another case (WHMCS kept «Resel1», WHM owner «resel1»): its accounts not seen. */
    public function test_a3_owner_compared_without_case(): void
    {
        $this->account('Resel1');
        $this->owned = ['cust1'];
        $this->ownerAs = 'resel1';

        $this->cronTerminates();

        $this->deletedNothing();
    }

    /**
     * A4. The confirmation is a stored property that any path reads: one left behind (staff's request killed by a fatal
     * timeout during a long terminatereseller, where `finally` does not run; or read by a cron/retry job running while
     * staff's click is in flight) lets a cron delete the reseller's customers.
     */
    public function test_a4_a_leftover_confirmation_is_not_honoured_by_a_cron(): void
    {
        $this->account();
        $this->owned = ['cust1'];
        $this->service->properties()->create(['key' => CPanel::CONFIRM, 'name' => 'Reseller termination confirmed by staff', 'value' => 'resel1']);

        $this->cronTerminates();

        $this->assertSame([], $this->sent('terminatereseller'), 'a cron deleted the reseller\'s customers on a confirmation left in the database');
    }

    /**
     * A5. Half-made: createacct worked, setupreseller failed (an upgrade is pending for staff), so WHM has a plain account.
     * The product's flag alone sends suspendreseller, which WHM refuses: the overdue account is never suspended.
     */
    public function test_a5_a_half_made_reseller_is_still_suspended(): void
    {
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'plain1']);
        $this->resellers = [];

        try {
            ExtensionHelper::suspendServer($this->service->fresh());
        } catch (Exception) {
        }

        $this->assertCount(1, $this->sent('suspendacct'), 'a half-made reseller (a plain account in WHM) was not suspended');
    }

    /** A6. nixpal: a reseller's login. A reseller product there (made «and staff told») is never suspended either. */
    public function test_a6_a_reseller_product_on_a_non_root_login_is_still_suspended(): void
    {
        $this->server->settings()->where('key', 'username')->update(['value' => 'nixpalres']);
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'nix1']);

        try {
            ExtensionHelper::suspendServer($this->service->fresh());
        } catch (Exception) {
        }

        $this->assertCount(1, $this->sent('suspendacct'), 'an account on a reseller login was not suspended');
    }

    // ---- held ----

    public function test_a_failed_listing_refuses_rather_than_removing(): void
    {
        $this->account();
        $this->listacctsBody = ['metadata' => ['result' => 0, 'reason' => 'timeout']];

        $this->cronTerminates();

        $this->deletedNothing();
    }

    public function test_a_product_toggled_off_keeps_the_guard_while_it_is_still_a_reseller(): void
    {
        $this->product->settings()->where('key', 'reseller')->update(['value' => '0']);
        $this->account();
        $this->owned = ['cust1'];

        $this->cronTerminates();

        $this->deletedNothing();
    }

    public function test_a_confirmation_for_another_service_does_not_carry(): void
    {
        $this->account();
        $this->owned = ['cust1'];
        $other = Service::factory()->create(['client_id' => $this->service->client_id, 'plan_id' => $this->service->plan_id,
            'product_id' => $this->product->id, 'server_id' => $this->server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 1]);
        $other->properties()->create(['key' => CPanel::CONFIRM, 'name' => 'c', 'value' => 'resel1']);

        $this->cronTerminates();

        $this->deletedNothing();
    }

    public function test_staff_typing_another_case_or_spaces_refuses_and_leaves_no_confirmation(): void
    {
        $this->account();
        $this->owned = ['cust1'];
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Adv' . uniqid(), 'permissions' => ['*']])->id]);

        try {
            $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
                ->callAction('changeStatus', ['action' => 'terminate', 'sendNotification' => false, 'confirmUsername' => ' RESEL1 ']);
        } catch (Throwable) {
            // app.debug rethrows the refusal
        }

        $this->assertCount(1, $this->sent('listaccts'), 'the terminate did not reach the server');
        $this->deletedNothing();
        $this->assertSame(0, $this->service->properties()->where('key', CPanel::CONFIRM)->count(), 'the typed confirmation was left behind');
        $this->assertNotSame(Service::STATUS_TERMINATED, $this->service->fresh()->status);
    }
}
