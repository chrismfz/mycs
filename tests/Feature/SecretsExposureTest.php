<?php

namespace Tests\Feature;

use App\Admin\Resources\ServerResource\Pages\EditServer;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Mail\Mail as MyCSMail;
use App\Mail\SystemMail;
use App\Models\DebugLog;
use App\Models\EmailLog;
use App\Models\Property;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\Redact;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sweep §6 (docs/sweeps/modules.md): secrets stay out of the email log, the debug log, the queue, the staff's forms and
 * the properties.
 */
class SecretsExposureTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Redact::forget();
        $user = User::factory()->create();
        $product = $this->createProduct();
        $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth(),
            'username' => 'examplegr', 'password' => 'S3cret!pw-welcome']);
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner ' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function server(string $extension, array $settings): Server
    {
        $server = Server::query()->create(['name' => $extension, 'extension' => $extension, 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, $settings);

        return $server->fresh();
    }

    public function test_the_welcome_mail_keeps_the_password_and_its_log_does_not(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);

        NotificationHelper::serverCreatedNotification($this->service->client, $this->service->fresh(), ['password' => 'Module!given-pw']);

        $mail = Mail::queued(MyCSMail::class, fn (MyCSMail $mail) => $mail->templateKey === 'new_server_created')->first();
        $this->assertStringContainsString('S3cret!pw-welcome', $mail->renderedHtml, 'the customer gets the login');
        $log = (string) EmailLog::query()->latest('id')->value('body');
        $this->assertStringContainsString('examplegr', $log);
        $this->assertStringNotContainsString('S3cret!pw-welcome', $log);
        $this->assertStringNotContainsString('Module!given-pw', $log);
    }

    public function test_mails_are_encrypted_in_the_queue(): void
    {
        $this->assertInstanceOf(ShouldBeEncrypted::class, (new \ReflectionClass(MyCSMail::class))->newInstanceWithoutConstructor());
        $this->assertInstanceOf(ShouldBeEncrypted::class, (new \ReflectionClass(SystemMail::class))->newInstanceWithoutConstructor());
    }

    public function test_the_debug_log_keeps_no_secret_of_a_request(): void
    {
        config(['settings.debug' => true]);
        $this->server('Virtualizor', ['ip' => 'panel.example.test', 'port' => '4085', 'key' => 'stored-api-key-123', 'password' => 'stored-api-pass-456']);
        Http::fake(['*' => Http::response(['data' => ['url' => 'https://host.example.test:2083/cpsess4182736/login'], 'token' => 'tok-789', 'echo' => 'stored-api-pass-456'])]);

        Http::withHeaders(['X-Api-Key' => 'header-key-000', 'Cookie' => 'whmsession=abc'])
            ->post('https://panel.example.test/index.php?act=adduser&adminapikey=url-key-111&adminapipass=url-pass-222', [
                'passwd' => 'body-pass-333', 'nested' => ['rootpass' => 'root-pass-444'], 'user' => 'visible-user',
            ]);

        $logged = json_encode(DebugLog::query()->sole()->context);
        foreach (['url-key-111', 'url-pass-222', 'body-pass-333', 'root-pass-444', 'header-key-000', 'whmsession=abc', 'cpsess4182736', 'tok-789', 'stored-api-pass-456'] as $secret) {
            $this->assertStringNotContainsString($secret, $logged, $secret);
        }
        $this->assertStringContainsString('visible-user', $logged, 'the rest is still there to debug with');
    }

    public function test_directadmin_keeps_the_accounts_password_encrypted_on_the_service_not_in_a_property(): void
    {
        $server = $this->server('DirectAdmin', ['host' => 'https://da.example.test:2222', 'username' => 'admin', 'password' => 'da-admin-pw']);
        $this->service->product->update(['server_id' => $server->id]);
        $this->service->product->settings()->create(['key' => 'package', 'value' => 'basic']);
        $this->service->forceFill(['password' => null, 'server_id' => $server->id])->save();
        Http::preventStrayRequests();
        Http::fake([
            '*/CMD_API_SHOW_RESELLER_IPS*' => Http::response('list[]=192.0.2.5'),
            '*/CMD_API_ACCOUNT_USER' => Http::response('error=0&text=created'),
            '*/CMD_API_LOGIN_KEYS' => Http::response('error=0&details=https://da.example.test/login'),
        ]);

        ExtensionHelper::createServer($this->service->fresh()->load('product'));

        $service = $this->service->fresh();
        $this->assertNull(Property::query()->where('key', 'directadmin_password')->first(), 'no password in a property');
        $this->assertGreaterThanOrEqual(16, strlen((string) $service->password));
        $this->assertNotSame($service->password, DB::table('services')->where('id', $service->id)->value('password'), 'stored encrypted');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/CMD_API_ACCOUNT_USER') && $request['passwd'] === $service->password);
    }

    public function test_old_directadmin_passwords_move_to_the_service_and_leave_the_audits(): void
    {
        config(['audit.console' => true]);
        $this->service->forceFill(['password' => null])->save();
        $property = $this->service->properties()->create(['key' => 'directadmin_password', 'name' => 'DirectAdmin password', 'value' => 'old-da-pw-1']);
        $audits = fn () => (string) DB::table('audits')->where('auditable_type', Property::class)->where('auditable_id', $property->id)->value('new_values');
        $this->assertStringContainsString('old-da-pw-1', $audits(), 'in clear before');

        (require database_path('migrations/2026_11_06_000000_move_directadmin_passwords_out_of_properties.php'))->up();

        $this->assertSame('old-da-pw-1', $this->service->fresh()->password);
        $this->assertNull(Property::query()->find($property->id));
        $this->assertStringNotContainsString('old-da-pw-1', $audits());
    }

    public function test_the_server_form_never_gets_a_stored_secret_and_keeps_it_when_left_empty(): void
    {
        $server = $this->server('DirectAdmin', ['host' => 'https://da.example.test:2222', 'username' => 'admin', 'password' => 'da-admin-pw']);

        $page = Livewire::actingAs($this->staff(), 'staff')->test(EditServer::class, ['record' => $server->getRouteKey()]);
        $this->assertNull($page->get('data.settings.password'), 'the stored secret went to the browser');
        $this->assertSame('admin', $page->get('data.settings.username'));
        $this->assertStringNotContainsString('da-admin-pw', $page->html());

        $page->set('data.settings.username', 'admin2')->call('save')->assertHasNoErrors();
        $this->assertSame('da-admin-pw', ExtensionHelper::settingsToArray($server->fresh()->settings)['password'], 'left empty: kept');
        $this->assertSame('admin2', ExtensionHelper::settingsToArray($server->fresh()->settings)['username']);

        Livewire::actingAs($this->staff(), 'staff')->test(EditServer::class, ['record' => $server->getRouteKey()])
            ->set('data.settings.password', 'da-admin-pw-2')->call('save')->assertHasNoErrors();
        $this->assertSame('da-admin-pw-2', ExtensionHelper::settingsToArray($server->fresh()->settings)['password']);
    }

    public function test_every_password_field_of_a_server_or_gateway_is_stored_encrypted(): void
    {
        foreach (['server' => glob(base_path('extensions/Servers/*'), GLOB_ONLYDIR), 'gateway' => glob(base_path('extensions/Gateways/*'), GLOB_ONLYDIR)] as $type => $dirs) {
            foreach ($dirs as $dir) {
                $name = basename($dir);
                try {
                    $config = ExtensionHelper::getConfig($type, $name, ['_' => null]);
                } catch (\Throwable) {
                    continue; // one that needs its connection to list its options
                }
                foreach ($config as $option) {
                    if (($option['type'] ?? null) === 'password') {
                        $this->assertTrue((bool) ($option['encrypted'] ?? false), "{$type} {$name}: {$option['name']} is a password stored in clear");
                    }
                }
            }
        }
    }
}
