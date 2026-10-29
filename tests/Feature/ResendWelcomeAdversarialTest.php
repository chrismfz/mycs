<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Livewire\Services\Show;
use App\Mail\Mail as MyCSMail;
use App\Models\Audit;
use App\Models\ClientUser;
use App\Models\EmailLog;
use App\Models\Notification as InAppNotification;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Service\WelcomeMail;
use App\Support\CurrentClient;
use App\Support\ServicePassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of #533 (resend welcome, generator, password set elsewhere). Failing tests are findings (ADV-533-N);
 * passing ones are attacks that held. The original docblock: WHMCS's «Resend Welcome Email», a generated password and a password set elsewhere (owner 2026-10-29): the welcome mail
 * goes again with the password stored now, to the owner only, the log without it, audited, limited.
 */
class ResendWelcomeAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'Old-Pass-123';

    private const NEW = 'N3w-Strong-Pass';

    private User $owner;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['settings.mail_disable' => false, 'settings.default_currency' => 'USD']);
        $server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->owner = User::factory()->create();
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $this->service = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addMonth(),
            'domain' => 'example.test', 'username' => 'exampl', 'password' => self::OLD]);
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'exampl']);
        Http::fake(fn (Request $request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/passwd')
            ? Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']]) : Http::response([], 404));
        RateLimiter::clear('service-password:' . $this->service->id);
    }

    private function staff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function page(?Staff $staff = null)
    {
        return $this->livewireAs($staff ?? $this->staff())->test(EditService::class, ['record' => $this->service->getRouteKey()]);
    }

    /** @return list<MyCSMail> */
    private function welcomes(): array
    {
        return Mail::queued(MyCSMail::class, fn (MyCSMail $mail) => $mail->templateKey === WelcomeMail::TEMPLATE)->values()->all();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Findings (fail on c276b8a4)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * ADV-533-1: a service with a password but no username (a product without a module, a VPS whose password staff
     * saved with «Save a password set elsewhere») passes refusal(), but the default welcome template prints the password
     * only inside `{% if service.username %}`: the customer gets a mail without any login details, while staff are
     * told «Welcome email sent». refusal() refuses the symmetric case (login without password) for exactly that reason.
     */
    public function test_adv_533_1_a_password_without_a_username_is_never_reported_as_sent_without_it(): void
    {
        $this->service->forceFill(['username' => null])->save();
        $staff = $this->staff();

        $this->page($staff)->callAction('storePassword', ['password' => 'Vps-Root-Pass-77', 'sendWelcome' => true])->assertHasNoActionErrors();

        foreach ($this->welcomes() as $mail) {
            $this->assertStringContainsString('Vps-Root-Pass-77', $mail->renderedHtml,
                'a welcome email was sent (and staff were told so) without the password it was resent for');
        }
        $this->assertNotSame([], $this->welcomes(), 'or it must be refused with a reason; here it went out');
    }

    /**
     * ADV-533-2: «3 an hour per service» is a fixed window that opens at the first send (Laravel's RateLimiter::hit):
     * one send, then two at minute 59 and three more at minute 61 make 5 mails with the password within two minutes
     * and 6 within 61 minutes.
     */
    public function test_adv_533_2_at_most_three_welcomes_in_any_sixty_minutes(): void
    {
        $staff = $this->staff();
        $this->page($staff)->callAction('resendWelcome');
        $this->travel(59)->minutes();
        $this->page($staff)->callAction('resendWelcome');
        $this->page($staff)->callAction('resendWelcome');
        $this->travel(2)->minutes();
        for ($i = 0; $i < 3; $i++) {
            $this->page($staff)->callAction('resendWelcome');
        }

        // Minutes 59 to 61: no more than PER_HOUR in any 60 minutes.
        $this->assertLessThanOrEqual(WelcomeMail::PER_HOUR, count($this->welcomes()) - 1,
            count($this->welcomes()) . ' welcome emails with the password went out within 61 minutes');
    }

    /**
     * ADV-533-3: a staff «Resend welcome email» goes through the whole notification (NotificationHelper::deliver), so the
     * owner also gets a new in-app (and push) notification «Your service is active» every time; WHMCS's resend is the
     * email only, and the PR describes it as «the same mail».
     */
    public function test_adv_533_3_a_resend_is_the_email_only_not_a_new_in_app_notification(): void
    {
        $before = InAppNotification::query()->where('user_id', $this->owner->id)->count();

        $this->page()->callAction('resendWelcome')->assertHasNoActionErrors();

        $this->assertCount(1, $this->welcomes());
        $this->assertSame($before, InAppNotification::query()->where('user_id', $this->owner->id)->count(),
            'a resend tells the customer again, in the app and by push, that the service just became active');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Attacks that held
    // ---------------------------------------------------------------------------------------------------------------

    /** Held: a forged «sendWelcome» on a suspended service (the checkbox is hidden there) stores, but mails nothing. */
    public function test_held_a_forged_send_welcome_on_a_suspended_service_mails_nothing(): void
    {
        $this->service->forceFill(['status' => Service::STATUS_SUSPENDED])->save();

        $this->page()->callAction('storePassword', ['password' => 'Stored-While-Susp-1', 'sendWelcome' => true]);

        $this->assertSame('Stored-While-Susp-1', $this->service->fresh()->password);
        $this->assertSame([], $this->welcomes());
        $this->assertSame(0, EmailLog::query()->count());
    }

    /** Held: staff with view-only roles never reach the actions (incl. the nested «generatePassword»), whatever they post. */
    public function test_held_partial_roles_reach_none_of_the_actions(): void
    {
        foreach ([['admin.services.viewAny'], ['admin.services.viewAny', 'admin.services.view'], ['admin.services.viewAny', 'admin.services.view', 'admin.clients.update']] as $permissions) {
            $staff = $this->staff($permissions);
            $this->page($staff)->assertForbidden();
            $this->assertFalse($staff->can('update', $this->service));
        }
    }

    /** Held: the email log, every audit and the in-app notification never hold a password across all three flows. */
    public function test_held_no_password_in_logs_audits_or_notifications(): void
    {
        $staff = $this->staff();
        $this->page($staff)->callAction('serverPassword', ['password' => self::NEW, 'sendWelcome' => true])->assertHasNoActionErrors();
        $this->page($staff)->callAction('storePassword', ['password' => 'Elsewhere-Pass-42', 'sendWelcome' => true])->assertHasNoActionErrors();
        $this->page($staff)->callAction('resendWelcome')->assertHasNoActionErrors();

        $this->assertCount(3, $this->welcomes());
        $haystack = json_encode([
            EmailLog::query()->get()->map->getAttributes(),
            Audit::query()->get()->map->getAttributes(),
            InAppNotification::query()->get()->map->getAttributes(),
        ]);
        foreach ([self::OLD, self::NEW, 'Elsewhere-Pass-42'] as $password) {
            $this->assertStringNotContainsString($password, $haystack);
        }
    }

    /** Held: once an action finished, its modal data (the typed password) is not left in the page's snapshot. */
    public function test_held_the_typed_password_does_not_stay_in_the_mounted_action(): void
    {
        $page = $this->page()->callAction('storePassword', ['password' => 'Elsewhere-Pass-42']);

        $this->assertSame([], (array) $page->get('mountedActions'));
    }

    /** Held: the mail goes to the owner only, never to another login of the account nor to the template's copies. */
    public function test_held_only_the_owner_even_with_other_logins_and_template_copies(): void
    {
        NotificationTemplate::query()->where('key', WelcomeMail::TEMPLATE)->update(['cc' => ['cc@staff.test'], 'bcc' => ['bcc@staff.test']]);
        $member = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->service->client_id, 'user_id' => $member->id, 'permissions' => null, 'granted_at' => now()]);

        $this->page()->callAction('resendWelcome')->assertHasNoActionErrors();

        [$mail] = $this->welcomes();
        $this->assertSame([['name' => null, 'address' => $this->owner->email]], $mail->to);
        $this->assertSame([], $mail->cc);
        $this->assertSame([], $mail->bcc);
        $this->assertFalse($mail->hasTo($member->email));
    }

    /** Held: a stale tab saving the form later does not write the old password back over one stored from another tab. */
    public function test_held_a_later_form_save_from_a_stale_tab_does_not_bring_back_the_old_password(): void
    {
        $stale = $this->page(); // opened before, with the old password in its form
        $this->page()->callAction('storePassword', ['password' => 'Set-In-Tab-B-1']);

        $stale->set('data.notes', 'edited in tab A')->call('save');

        $this->assertSame('Set-In-Tab-B-1', $this->service->fresh()->password);
    }

    /** Held: the customer's generator needs «manageproducts» and a password-changing module. */
    public function test_held_the_customer_generator_needs_the_right_to_manage(): void
    {
        $member = User::factory()->create();
        $client = $this->owner->client();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $member->id, 'permissions' => ['products', 'productsso'], 'granted_at' => now()]);
        $this->actingAs($member)->withSession($this->loginUser($member, [CurrentClient::SESSION_KEY => $client->id]));

        $page = Livewire::test(Show::class, ['service' => $this->service])->assertOk();
        $this->assertFalse($page->get('canChangePassword'));
        $page->call('generateServicePassword')->assertNotFound(); // the policy's denial answers 404 here
    }

    /** Held: the generator's charset has no look-alikes, every set appears, and it is not biased to a fixed position. */
    public function test_held_the_generator_charset_and_spread(): void
    {
        $firsts = [];
        for ($i = 0; $i < 400; $i++) {
            $password = ServicePassword::generate();
            $this->assertDoesNotMatchRegularExpression('/[0O1lIio]/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[2-9]/', $password);
            $this->assertMatchesRegularExpression('/[-_.!@]/', $password);
            $firsts[$password[0]] = true;
        }
        $this->assertGreaterThan(30, count($firsts), 'the first character is spread over the whole alphabet');
        $this->assertSame(12, strlen(ServicePassword::generate(4)));
    }
}
