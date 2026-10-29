<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Livewire\Services\Show;
use App\Mail\Mail as MyCSMail;
use App\Models\Audit;
use App\Models\EmailLog;
use App\Models\License;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Service\WelcomeMail;
use App\Support\CurrentClient;
use App\Support\ServicePassword;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WHMCS's «Resend Welcome Email», a generated password and a password set elsewhere (owner 2026-10-29): the welcome mail
 * goes again with the password stored now, to the owner only, the log without it, audited, limited.
 */
class ResendWelcomeTest extends TestCase
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

    private function events(string $event)
    {
        return Audit::query()->where('auditable_type', $this->service->getMorphClass())->where('auditable_id', $this->service->id)
            ->where('event', $event)->get();
    }

    public function test_the_resend_carries_the_current_password_and_the_log_does_not(): void
    {
        $staff = $this->staff();
        $this->page($staff)->callAction('resendWelcome')->assertHasNoActionErrors();

        [$mail] = $this->welcomes();
        $this->assertStringContainsString(self::OLD, $mail->renderedHtml);
        $this->assertTrue($mail->hasTo($this->owner->email));
        $this->assertSame([], $mail->cc);
        $this->assertSame([], $mail->bcc);
        $log = EmailLog::query()->where('template_key', WelcomeMail::TEMPLATE)->sole();
        $this->assertStringContainsString('exampl', (string) $log->body);
        $this->assertStringNotContainsString(self::OLD, (string) $log->body);
        $this->assertSame($this->owner->email, $log->to);

        $audit = $this->events('welcome_resent')->sole();
        $this->assertSame(['staff_id' => $staff->id, 'to' => $this->owner->email, 'email_log_id' => $log->id], $audit->new_values);
        $this->assertStringNotContainsString(self::OLD, json_encode($audit->getAttributes()));
    }

    public function test_the_confirmation_names_the_owner_and_never_shows_the_password(): void
    {
        $page = $this->page()->mountAction('resendWelcome');

        $page->assertSee($this->owner->email);
        $this->assertStringNotContainsString(self::OLD, $page->html());
        $this->assertSame([], $this->welcomes(), 'nothing is sent before the confirmation');
    }

    public function test_nothing_is_sent_without_a_stored_password(): void
    {
        // An imported service before its passwords were decrypted (docs/importer.md).
        $this->service->forceFill(['password' => null])->save();

        $this->page()->callAction('resendWelcome');

        $this->assertSame([], $this->welcomes());
        $this->assertSame(0, EmailLog::query()->count());
        $this->assertCount(0, $this->events('welcome_resent'));
        $this->assertNotNull(WelcomeMail::refusal($this->service->fresh()));
    }

    public function test_a_password_that_does_not_decrypt_counts_as_none(): void
    {
        \DB::table('services')->where('id', $this->service->id)->update(['password' => 'not-a-ciphertext']);

        $this->assertNull(WelcomeMail::password($this->service->fresh()));
        $this->expectException(DisplayException::class);
        WelcomeMail::resend($this->service, $this->staff());
    }

    public function test_only_an_active_service(): void
    {
        foreach ([Service::STATUS_SUSPENDED, Service::STATUS_PENDING, 'cancelled', 'terminated'] as $status) {
            $this->service->forceFill(['status' => $status])->save();
            $this->assertNotNull(WelcomeMail::refusal($this->service->fresh()), $status);
            $this->page()->assertActionHidden('resendWelcome');
        }
        $this->assertSame([], $this->welcomes());
    }

    public function test_a_service_without_login_or_license_is_refused(): void
    {
        $this->service->forceFill(['username' => null, 'password' => null])->save();
        $this->assertNotNull(WelcomeMail::refusal($this->service->fresh()), 'no login, no license: nothing to send');
    }

    public function test_a_license_without_a_login_is_resent_with_its_key(): void
    {
        $this->service->forceFill(['username' => null, 'password' => null])->save();
        $this->service->product->update(['email_template' => 'Key: {{ service.license.license_key }}']);
        License::query()->create(['service_id' => $this->service->id, 'license_key' => 'ZCAgent-0123456789abcdef', 'status' => License::STATUS_ACTIVE]);

        $this->page()->callAction('resendWelcome');

        [$mail] = $this->welcomes();
        $this->assertStringContainsString('ZCAgent-0123456789abcdef', $mail->renderedHtml);
    }

    public function test_the_audit_names_this_services_mail_not_another_ones(): void
    {
        // Another service of the same account mailed at the same moment (code review of #533).
        $other = $this->service->replicate()->fill(['domain' => 'other.test']);
        $other->save();
        Service::created(fn () => null);
        NotificationHelper::serverCreatedNotification($other->client, $other);
        $staff = $this->staff();

        $log = WelcomeMail::resend($this->service, $staff);

        $this->assertSame($this->service->id, (int) $log->related_id);
        $this->assertSame($log->id, $this->events('welcome_resent')->sole()->new_values['email_log_id']);
    }

    public function test_the_modal_submits_with_send(): void
    {
        $submit = $this->page()->mountAction('resendWelcome')->instance()->getMountedAction()?->getModalSubmitAction();

        $this->assertSame('submit', $submit?->getName());
        $this->assertSame(__('Send'), $submit?->getLabel());
        $this->assertFalse($submit->isDisabled());
        $this->assertTrue($submit->canSubmitForm());
    }

    public function test_send_is_disabled_when_it_would_be_refused(): void
    {
        $this->service->forceFill(['password' => null])->save();

        $page = $this->page()->mountAction('resendWelcome');
        $submit = $page->instance()->getMountedAction()?->getModalSubmitAction();
        // The modal's own «Send», not the action again (re-review of #533: a closure parameter not named $action got it).
        $this->assertSame('submit', $submit?->getName());
        $this->assertTrue((bool) $submit?->isDisabled());
        $this->assertFalse((bool) $page->instance()->getMountedAction()?->isDisabled());
        $page->callMountedAction();
        $this->assertSame([], $this->welcomes());
    }

    public function test_a_password_without_a_username_is_in_the_welcome(): void
    {
        // A VPS's root password, a product with no module (ADV-533-1).
        $this->service->forceFill(['username' => null, 'password' => 'Vps-Root-Pass-77'])->save();

        $this->page()->callAction('resendWelcome');

        [$mail] = $this->welcomes();
        $this->assertStringContainsString('Vps-Root-Pass-77', $mail->renderedHtml);
        $this->assertStringNotContainsString('Vps-Root-Pass-77', (string) EmailLog::query()->sole()->body);
    }

    public function test_the_migration_updates_a_seeded_welcome_and_leaves_an_edited_one(): void
    {
        $migration = include base_path('database/migrations/2027_01_23_000000_welcome_mail_password_without_username.php');
        $template = fn () => \DB::table('notification_templates')->where('key', WelcomeMail::TEMPLATE)->first();
        $seeded = $template();

        $migration->down(); // as an install seeded before this change
        $this->assertStringNotContainsString('{% else %}', $template()->body);
        $this->assertStringNotContainsString('{% else %}', json_decode($template()->translations, true)['el']['body']);
        $migration->up();
        $this->assertSame($seeded->body, $template()->body);
        $this->assertSame(json_decode($seeded->translations, true), json_decode($template()->translations, true));
        $migration->up(); // twice: no change
        $this->assertSame($seeded->body, $template()->body);

        $edited = 'Hello {{ service.username }} {{ service_password }}';
        \DB::table('notification_templates')->where('key', WelcomeMail::TEMPLATE)->update(['body' => $edited]);
        $migration->up();
        $this->assertSame($edited, $template()->body);
    }

    public function test_a_resend_is_the_mail_only(): void
    {
        $this->page()->callAction('resendWelcome');

        $this->assertCount(1, $this->welcomes());
        $this->assertSame(0, Notification::query()->where('user_id', $this->owner->id)->count());
    }

    public function test_it_is_limited_per_hour(): void
    {
        $staff = $this->staff();
        for ($i = 0; $i < WelcomeMail::PER_HOUR + 2; $i++) {
            $this->page($staff)->callAction('resendWelcome');
        }

        $this->assertCount(WelcomeMail::PER_HOUR, $this->welcomes());
        $this->assertCount(WelcomeMail::PER_HOUR, $this->events('welcome_resent'));
    }

    public function test_staff_without_update_on_services_cannot_resend_change_or_store(): void
    {
        // The page is the service's edit page: without «update» staff do not get it, nor its actions.
        $viewer = $this->staff(['admin.services.viewAny']);
        $this->actingAs($viewer)->get(ServiceResource::getUrl('edit', ['record' => $this->service]))->assertForbidden();
        $this->page($viewer)->assertForbidden();

        $this->assertSame([], $this->welcomes());
        $this->assertSame(self::OLD, $this->service->fresh()->password);
        $this->assertSame([], Http::recorded()->all());
    }

    public function test_the_generator_meets_the_rule(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $password = ServicePassword::generate();
            $this->assertSame(ServicePassword::LENGTH, strlen($password));
            $this->assertTrue(Validator::make(['p' => $password], ['p' => [ServicePassword::rule()]])->passes(), $password);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_.!@]+$/', $password);
        }
        $this->assertNotSame(ServicePassword::generate(), ServicePassword::generate());
    }

    public function test_generate_fills_the_change_on_server_form(): void
    {
        $page = $this->page()->mountAction('serverPassword')
            ->callAction(TestAction::make('generatePassword')->schemaComponent('password', 'mountedActionSchema0'));

        $generated = (string) $page->get('mountedActions.0.data.password');
        $this->assertTrue(Validator::make(['p' => $generated], ['p' => [ServicePassword::rule()]])->passes(), $generated);
    }

    public function test_a_password_changed_on_the_server_then_goes_in_the_resend(): void
    {
        $staff = $this->staff();
        $this->page($staff)->callAction('serverPassword', ['password' => self::NEW, 'sendWelcome' => true])->assertHasNoActionErrors();

        $this->assertSame(self::NEW, $this->service->fresh()->password);
        [$mail] = $this->welcomes();
        $this->assertStringContainsString(self::NEW, $mail->renderedHtml);
        $this->assertStringNotContainsString(self::OLD, $mail->renderedHtml);
        $this->assertStringNotContainsString(self::NEW, (string) EmailLog::query()->sole()->body);
        $this->assertSame(['staff_id' => $staff->id], $this->events('password_changed_on_server')->sole()->new_values);

        // And a later resend carries it too.
        $this->page($staff)->callAction('resendWelcome');
        $this->assertStringContainsString(self::NEW, $this->welcomes()[1]->renderedHtml);
    }

    public function test_no_welcome_unless_asked(): void
    {
        $this->page()->callAction('serverPassword', ['password' => self::NEW])->assertHasNoActionErrors();

        $this->assertSame(self::NEW, $this->service->fresh()->password);
        $this->assertSame([], $this->welcomes());
    }

    public function test_the_server_rule_still_applies(): void
    {
        $this->page()->callAction('serverPassword', ['password' => 'weakpass'])->assertHasActionErrors(['password']);

        $this->assertSame([], Http::recorded()->all());
    }

    public function test_a_password_set_elsewhere_is_stored_without_calling_the_server(): void
    {
        $staff = $this->staff();
        $this->page($staff)->callAction('storePassword', ['password' => 'set-by-customer-9', 'sendWelcome' => true])->assertHasNoActionErrors();

        $this->assertSame([], Http::recorded()->all(), 'nothing asked of the server');
        $this->assertSame('set-by-customer-9', $this->service->fresh()->password);
        $this->assertSame(['staff_id' => $staff->id], $this->events('password_stored')->sole()->new_values);
        // The attribute's own audit never has the value.
        $this->assertStringNotContainsString('set-by-customer-9', json_encode(Audit::query()->get()->map->getAttributes()));
        [$mail] = $this->welcomes();
        $this->assertStringContainsString('set-by-customer-9', $mail->renderedHtml);
    }

    public function test_a_stored_password_survives_a_form_save_afterwards(): void
    {
        $page = $this->page();
        $page->callAction('storePassword', ['password' => 'set-by-customer-9'])->call('save');

        $this->assertSame('set-by-customer-9', $this->service->fresh()->password);
    }

    public function test_a_change_the_service_did_not_store_is_never_mailed(): void
    {
        // The server took it, but storing it failed (cPanel reports it and still answers true).
        Service::saving(function (Service $service) {
            if ($service->isDirty('password') && $service->password === self::NEW) {
                throw new \RuntimeException('database down');
            }
        });

        $this->page()->callAction('serverPassword', ['password' => self::NEW, 'sendWelcome' => true]);

        $this->assertSame(self::OLD, $this->service->fresh()->password);
        $this->assertSame([], $this->welcomes());
    }

    public function test_the_customer_may_generate_one_too(): void
    {
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));
        $page = Livewire::test(Show::class, ['service' => $this->service])->call('generateServicePassword');

        $generated = $page->get('generatedPassword');
        $this->assertSame($generated, $page->get('newPassword'));
        $this->assertSame($generated, $page->get('newPasswordConfirmation'));
        $page->assertSee($generated);

        // Typed over: the box no longer shows a password that would not be set (code review of #533).
        $page->set('newPassword', 'Typed-0ver-pass')->assertDontSee($generated)
            ->set('newPassword', $generated);

        $page->call('changeServicePassword');
        $this->assertSame($generated, $this->service->fresh()->password);
        $this->assertSame('', $page->get('generatedPassword'), 'not kept after the change');
    }
}
