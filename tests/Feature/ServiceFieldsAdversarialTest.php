<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Helpers\TemplateRenderer;
use App\Models\ApiKey;
use App\Models\ClientUser;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of f3283d0 (service fields: domain, username, password, IPs, notes, suspension reason).
 * test_* without «holds» are findings (fail on f3283d0); test_holds_* are attacks that did not work.
 */
class ServiceFieldsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const XSS = '<img src=x onerror=alert(1)>';

    private User $owner;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $this->owner = User::factory()->create();
        $product = $this->createProduct();
        $this->service = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth(),
            'domain' => 'example.gr', 'username' => 'examplegr', 'password' => 'S3cret!pw', 'dedicated_ip' => '192.0.2.10',
            'assigned_ips' => "192.0.2.11\n192.0.2.12", 'notes' => 'STAFFNOTE-abuse-history']);
    }

    private function staff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function customerPage(User $user, Service $service)
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $service->client_id]))
            ->get(route('services.show', $service));
    }

    // ---- findings ------------------------------------------------------------------------------------------------

    /**
     * F1 (High): staff changing the password on the edit page stores it encrypted TWICE. applyStaffChanges() takes the
     * probe's raw (already encrypted) dirty value and forceFill()s it, which runs the `encrypted` cast again. The
     * customer then sees (and every server module receives) the ciphertext instead of the password.
     */
    public function test_staff_changing_the_password_stores_the_password_not_its_ciphertext(): void
    {
        $staff = $this->staff();

        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->fillForm(['password' => 'N3w!Passw0rd'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('N3w!Passw0rd', $this->service->fresh()->password);
    }

    /** F1, the same through applyStaffChanges directly (the path every staff save takes). */
    public function test_apply_staff_changes_encrypts_the_password_once(): void
    {
        $this->service->applyStaffChanges(['password' => 'Changed!1']);

        $this->assertSame('Changed!1', $this->service->fresh()->password);
    }

    /**
     * F2 (Medium): «Admin Notes — Staff only» are readable from every staff-edited template (emails, in-app
     * notifications, the product's email snippet): {{ service.notes }} renders them to the customer. The renderer's
     * guard only blocks $hidden fields, and notes is not one. Also lets staff with template rights but no services
     * rights exfiltrate them (send a test notification to themselves).
     */
    public function test_a_template_cannot_render_the_staff_notes(): void
    {
        $out = TemplateRenderer::render('Your service: {{ service.domain }} [{{ service.notes }}]', ['service' => $this->service->fresh()]);

        $this->assertStringContainsString('example.gr', $out);
        $this->assertStringNotContainsString('STAFFNOTE', $out);
    }

    /** F2, through the product's snippet (product_info) every service notification renders. */
    public function test_the_product_snippet_cannot_render_the_staff_notes(): void
    {
        $service = $this->service->fresh();
        $service->product->forceFill(['email_template' => 'Login: {{ service.username }} {{ service.notes }}'])->save();

        $context = TemplateRenderer::context(['service' => $service->fresh()]);

        $this->assertStringContainsString('examplegr', (string) $context['product_info']);
        $this->assertStringNotContainsString('STAFFNOTE', (string) $context['product_info']);
    }

    /**
     * F3 (Low): the edit form shows the stored password (filled from the record); staff emptying the field cannot
     * remove it: an empty field is «not dehydrated», so a wrong/obsolete password can never be cleared from the admin.
     */
    public function test_staff_can_clear_the_password(): void
    {
        $staff = $this->staff();

        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->assertFormSet(['password' => 'S3cret!pw'])
            ->fillForm(['password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->service->fresh()->password);
    }

    /**
     * F4 (Low): the cron writes the English literal 'Overdue on Payment' into suspend_reason and the client area prints
     * it verbatim, so a Greek customer reads English (CLAUDE.md: customer strings in lang/).
     */
    public function test_the_cron_suspension_reason_is_shown_in_the_customers_language(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00');
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_invoice' => 0]);
        $this->service->update(['expires_at' => '2026-10-10']);
        $this->artisan('app:cron-job');
        $this->assertSame('suspended', $this->service->fresh()->status);

        app()->setLocale('el');
        $html = $this->customerPage($this->owner, $this->service->fresh())->assertOk()->getContent();
        Carbon::setTestNow();

        $this->assertTrue(str_contains($html, __('services.suspend_reason')), 'the reason row is shown');
        $this->assertFalse(str_contains($html, 'Overdue on Payment'), 'an English reason on the Greek client area');
    }

    // ---- attacks that hold ---------------------------------------------------------------------------------------

    public function test_holds_templates_cannot_read_the_password(): void
    {
        $service = $this->service->fresh();
        $this->assertSame('[]', TemplateRenderer::render('[{{ service.password }}]', ['service' => $service]));
        $this->assertSame('[]', TemplateRenderer::render('[{% for s in service.client.services %}{{ s.password }}{% endfor %}]', ['service' => $service]));
    }

    public function test_holds_the_admin_api_never_returns_the_password_or_notes(): void
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 't', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => ['admin.services.viewAny', 'admin.services.view', 'admin.clients.viewAny', 'admin.clients.view']]);

        foreach ([route('api.v1.admin.services.show', $this->service), route('api.v1.admin.services.index'),
            route('api.v1.admin.services.show', $this->service) . '?include=client,properties'] as $url) {
            $body = $this->withToken($token)->getJson($url)->getContent();
            $this->assertStringNotContainsString('S3cret', $body, $url);
            $this->assertStringNotContainsString('STAFFNOTE', $body, $url);
        }
    }

    public function test_holds_serialization_has_no_password(): void
    {
        $json = $this->service->fresh()->toJson() . json_encode($this->owner->client()->load('services')->toArray());
        $this->assertStringNotContainsString('S3cret', $json);
        $this->assertNotSame('S3cret!pw', DB::table('services')->where('id', $this->service->id)->value('password'));
    }

    public function test_holds_another_customer_cannot_open_the_service(): void
    {
        $other = User::factory()->create();
        $response = $this->customerPage($other, $this->service);
        $this->assertContains($response->status(), [403, 404]);
        $this->assertStringNotContainsString('S3cret', (string) $response->getContent());
        $this->assertStringNotContainsString('example.gr', (string) $response->getContent());
    }

    public function test_holds_an_invoices_only_sub_user_sees_nothing_of_the_service(): void
    {
        $viewer = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->owner->client()->id, 'user_id' => $viewer->id, 'permissions' => ['invoices'], 'granted_at' => now()]);

        $response = $this->customerPage($viewer, $this->service);
        $this->assertContains($response->status(), [403, 404]);
        $this->assertStringNotContainsString('S3cret', (string) $response->getContent());
    }

    public function test_holds_a_products_only_sub_user_gets_neither_login_nor_notes_in_the_page_or_snapshot(): void
    {
        $viewer = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->owner->client()->id, 'user_id' => $viewer->id, 'permissions' => ['products'], 'granted_at' => now()]);

        $html = $this->customerPage($viewer, $this->service)->assertOk()->getContent();
        $this->assertStringContainsString('example.gr', $html);
        $this->assertStringNotContainsString('S3cret', $html);
        $this->assertStringNotContainsString('examplegr', $html);
        $this->assertStringNotContainsString('STAFFNOTE', $html);
    }

    public function test_holds_the_owner_never_gets_the_staff_notes(): void
    {
        $html = $this->customerPage($this->owner, $this->service)->assertOk()->getContent();
        $this->assertStringNotContainsString('STAFFNOTE', $html);
    }

    public function test_holds_staff_without_update_cannot_open_the_edit_page(): void
    {
        $support = $this->staff(['admin.services.viewAny']);

        $this->actingAs($support, 'staff')->get(ServiceResource::getUrl('edit', ['record' => $this->service]))->assertForbidden();
    }

    public function test_holds_the_password_is_not_in_the_admin_list_or_global_search(): void
    {
        $support = $this->staff(['admin.services.viewAny']);
        $this->actingAs($support, 'staff');

        $this->livewireAs($support)->test(ListService::class)->assertOk()->assertDontSee('S3cret')->assertDontSee('STAFFNOTE');
        $titles = ServiceResource::getGlobalSearchResults('example.gr')->map(fn ($r) => (string) $r->title . json_encode($r->details))->implode('|');
        $this->assertStringContainsString('#' . $this->service->id, $titles);
        $this->assertStringNotContainsString('S3cret', $titles);
    }

    public function test_holds_hostile_domain_ips_and_reason_are_escaped_for_the_customer(): void
    {
        $this->service->forceFill(['status' => 'suspended', 'domain' => self::XSS, 'dedicated_ip' => self::XSS,
            'assigned_ips' => self::XSS, 'suspend_reason' => self::XSS, 'username' => self::XSS])->save();

        $html = $this->customerPage($this->owner, $this->service->fresh())->assertOk()->getContent();
        $this->assertStringNotContainsString(self::XSS, $html);
        $this->assertStringContainsString(e(self::XSS), $html);
    }

    public function test_holds_hostile_domain_and_ip_are_escaped_in_the_admin_list_and_search_title(): void
    {
        $this->service->forceFill(['domain' => self::XSS, 'dedicated_ip' => self::XSS])->save();
        $staff = $this->staff();
        $this->actingAs($staff, 'staff');

        $this->livewireAs($staff)->test(ListService::class)->assertOk()->assertDontSee(self::XSS, false);
        $title = ServiceResource::getGlobalSearchResultTitle($this->service->fresh());
        $this->assertIsString($title, 'a plain string is escaped by Filament; an Htmlable would not be');
    }

    public function test_holds_the_search_is_bound_not_interpolated(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff, 'staff');

        $this->livewireAs($staff)->test(ListService::class)
            ->set('tableSearch', "x') OR 1=1 --")
            ->assertOk()
            ->assertCanNotSeeTableRecords([$this->service]);
        $this->assertSame(1, Service::query()->count());
    }

    public function test_holds_a_save_without_touching_the_password_keeps_it_and_writes_no_audit_of_it(): void
    {
        config(['audit.console' => true]);
        $staff = $this->staff();
        $before = DB::table('services')->where('id', $this->service->id)->value('password');

        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->fillForm(['notes' => 'changed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($before, DB::table('services')->where('id', $this->service->id)->value('password'));
        $this->assertSame('S3cret!pw', $this->service->fresh()->password);
        foreach (DB::table('audits')->get(['old_values', 'new_values']) as $audit) {
            $this->assertStringNotContainsString('S3cret', $audit->old_values . $audit->new_values);
        }
    }
}
