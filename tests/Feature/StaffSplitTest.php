<?php

namespace Tests\Feature;

use App\Admin\Auth\EditProfile;
use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Admin\Resources\UserResource\Pages\EditUser;
use App\Helpers\ExtensionHelper;
use App\Livewire\Auth\Tfa;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\MoneyEvent;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Actor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use LogicException;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Staff apart from customers (docs/accounts.md, PR 1): their own table, guard and login; ids that coincide never
 * mean the same person; a staff reply is told by its author; «Login as client» keeps the staff member as the actor.
 */
class StaffSplitTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceOf(User $user): Invoice
    {
        return Invoice::factory()->create(['user_id' => $user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
    }

    /** A customer and a member of staff with the same id: the coincidence the split must never confuse. */
    private function twins(array $permissions = []): array
    {
        $customer = User::factory()->create();
        $staff = Staff::factory()->withPermissions($permissions)->create();
        DB::table('staff')->where('id', $staff->id)->update(['id' => $customer->id]);

        return [$customer, Staff::query()->findOrFail($customer->id)];
    }

    public function test_a_customer_is_never_staff_and_staff_are_never_a_customer(): void
    {
        $customer = User::factory()->create(['password' => Hash::make('password')]);
        $staff = Staff::factory()->admin()->create(['email' => $customer->email, 'password' => Hash::make('password')]);

        // The customer's session does not open the admin.
        $this->actingAs($customer)->withSession($this->loginUser($customer))->get('/admin')
            ->assertRedirect(route('filament.admin.auth.login'));

        // Staff logged in at the admin are nobody in the client area.
        Auth::forgetGuards();
        $this->actingAs($staff);
        $this->assertTrue(Auth::guard('staff')->check());
        $this->assertNull(Auth::guard('web')->user());
        $this->assertFalse($staff->is($customer));
    }

    public function test_a_staff_member_with_a_customers_id_owns_nothing_of_theirs(): void
    {
        [$customer, $staff] = $this->twins(['admin.widgets.overview']);
        $invoice = $this->invoiceOf($customer);
        $service = Service::factory()->create(['user_id' => $customer->id]);
        $ticket = Ticket::factory()->create(['user_id' => $customer->id]);

        $this->assertSame($customer->id, $staff->id);
        foreach (['view', 'update'] as $ability) {
            $this->assertFalse(Gate::forUser($staff)->allows($ability, $invoice), "invoice $ability");
            $this->assertFalse(Gate::forUser($staff)->allows($ability, $service), "service $ability");
            $this->assertFalse(Gate::forUser($staff)->allows($ability, $ticket), "ticket $ability");
        }
        // The customer still does.
        $this->assertTrue(Gate::forUser($customer)->allows('view', $invoice));
        $this->assertTrue(Gate::forUser($customer)->allows('view', $service));
    }

    public function test_staff_see_a_customers_records_by_their_permission(): void
    {
        $customer = User::factory()->create();
        $invoice = $this->invoiceOf($customer);
        $staff = Staff::factory()->withPermissions(['admin.invoices.viewAny'])->create();

        $this->assertTrue(Gate::forUser($staff)->allows('view', $invoice));
        $this->assertFalse(Gate::forUser($staff)->allows('update', $invoice));
    }

    public function test_a_disabled_member_has_no_permission_and_no_admin(): void
    {
        $staff = Staff::factory()->admin()->create(['active' => false]);

        $this->assertFalse($staff->hasPermission('admin.invoices.viewAny'));
        $this->assertFalse($staff->canAccessPanel(Filament::getPanel('admin')));
        $this->actingAs($staff)->get('/admin')->assertForbidden();
    }

    public function test_a_staff_reply_is_told_by_its_author_not_by_ids(): void
    {
        [$customer, $staff] = $this->twins(['admin.tickets.viewAny', 'admin.tickets.update']);
        $ticket = Ticket::factory()->create(['user_id' => $customer->id, 'status' => 'open']);

        $this->actingAs($staff);
        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->set('data.message', 'We are on it.')
            ->call('send');

        $reply = TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $this->assertSame($staff->id, $reply->staff_id);
        $this->assertNull($reply->user_id);
        $this->assertTrue($reply->isFromStaff());
        $this->assertSame('replied', $ticket->fresh()->status);

        // The customer's own reply, with the same id, is the customer's.
        $own = $ticket->messages()->create(['user_id' => $customer->id, 'message' => 'Thanks']);
        $this->assertFalse($own->isFromStaff());
        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_a_ticket_is_assigned_to_staff(): void
    {
        $staff = Staff::factory()->admin()->create();
        $ticket = Ticket::factory()->create(['user_id' => User::factory()->create()->id, 'assigned_to' => $staff->id]);

        $this->assertTrue($ticket->assignedTo->is($staff));
    }

    public function test_money_moved_in_the_admin_is_the_staff_members(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        [$customer, $staff] = $this->twins(['admin.invoices.update']);
        $invoice = $this->invoiceOf($customer);
        $invoice->items()->create(['description' => 'Line', 'quantity' => 1, 'price' => 10]);

        $this->actingAs($staff);
        $this->assertTrue(Actor::current()->is($staff));
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_staff_1', currency: 'usd');

        $event = MoneyEvent::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame((new Staff)->getMorphClass(), $event->actor_type);
        $this->assertSame($staff->id, (int) $event->actor_id);
    }

    public function test_login_as_client_shows_the_customer_and_keeps_the_staff_member_as_actor(): void
    {
        $customer = User::factory()->create();
        $staff = Staff::factory()->withPermissions(['admin.users.viewAny', 'admin.users.update', 'admin.users.impersonate'])->create();

        $this->actingAs($staff);
        Livewire::test(EditUser::class, ['record' => $customer->getRouteKey()])
            ->callAction('impersonate');

        $this->assertSame($customer->id, Session::get('impersonating'));
        $audit = Audit::query()->where('auditable_type', $customer->getMorphClass())->where('auditable_id', $customer->id)
            ->where('event', 'impersonated')->firstOrFail();
        $this->assertSame($staff->getMorphClass(), $audit->user_type);
        $this->assertSame($staff->id, (int) $audit->user_id);

        // In the client area: the customer's view, the staff member acting.
        $this->withSession(['impersonating' => $customer->id])->get('/dashboard')->assertOk();
        Auth::guard('web')->setUser($customer);
        Auth::shouldUse('web');
        Session::put('impersonating', $customer->id);
        $this->assertTrue(Actor::current()->is($staff));
    }

    public function test_login_as_client_needs_the_permission(): void
    {
        $customer = User::factory()->create();
        $staff = Staff::factory()->withPermissions(['admin.users.viewAny', 'admin.users.update'])->create();

        $this->actingAs($staff);
        Livewire::test(EditUser::class, ['record' => $customer->getRouteKey()])
            ->assertActionHidden('impersonate');

        // A session that says so is not enough without the permission.
        $this->withSession(['impersonating' => $customer->id])->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_a_password_set_by_staff_ends_the_customers_sessions(): void
    {
        $customer = User::factory()->create();
        UserSession::query()->create(['user_id' => $customer->id, 'ip_address' => '1.2.3.4', 'user_agent' => 'x', 'last_activity' => now()]);
        $staff = Staff::factory()->withPermissions(['admin.users.viewAny', 'admin.users.update'])->create();

        $this->actingAs($staff);
        Livewire::test(EditUser::class, ['record' => $customer->getRouteKey()])
            ->set('data.password', 'a-new-password-123')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, UserSession::query()->where('user_id', $customer->id)->count());
    }

    public function test_a_member_with_history_is_disabled_not_deleted(): void
    {
        $staff = Staff::factory()->admin()->create();
        $ticket = Ticket::factory()->create(['user_id' => User::factory()->create()->id]);
        $ticket->messages()->create(['staff_id' => $staff->id, 'message' => 'Hello']);
        $boss = Staff::factory()->admin()->create();

        $this->assertFalse(Gate::forUser($boss)->allows('delete', $staff));
        try {
            $staff->delete();
            $this->fail('deleted with ticket replies');
        } catch (LogicException) {
        }
        $this->assertNotNull($staff->fresh());
        $this->assertSame(1, TicketMessage::query()->where('staff_id', $staff->id)->count());
    }

    public function test_nobody_deletes_themselves_or_who_outranks_them(): void
    {
        $support = Staff::factory()->withPermissions(['admin.staff.viewAny', 'admin.staff.update', 'admin.staff.delete'])->create();
        $owner = Staff::factory()->admin()->create();

        $this->assertFalse(Gate::forUser($support)->allows('delete', $support));
        $this->assertFalse(Gate::forUser($support)->allows('delete', $owner));
        $this->assertFalse(Gate::forUser($support)->allows('update', $owner));
        $this->assertTrue(Gate::forUser($owner)->allows('update', $support));
    }

    public function test_staff_edit_their_own_profile(): void
    {
        $staff = Staff::factory()->admin()->create(['first_name' => 'Old']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->livewireAs($staff)->test(EditProfile::class)
            ->set('data.first_name', 'New')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New', $staff->fresh()->first_name);
    }

    public function test_customer_2fa_backs_off_for_fifteen_minutes(): void
    {
        $customer = User::factory()->create(['tfa_secret' => 'JBSWY3DPEHPK3PXP']);
        Session::put('2fa', ['user_id' => $customer->id, 'remember' => false, 'expires' => now()->addMinutes(10)]);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Tfa::class)->set('code', '000000')->call('verify');
        }
        Livewire::test(Tfa::class)->set('code', '000000')->call('verify')->assertHasErrors('code');

        $this->assertGreaterThan(600, RateLimiter::availableIn('2fa:' . $customer->id));
    }
}
