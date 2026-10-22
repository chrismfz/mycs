<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Admin\Resources\DomainRegistrarConnectionResource\Pages\CreateDomainRegistrarConnection;
use App\Admin\Resources\DomainRegistrarConnectionResource\Pages\EditDomainRegistrarConnection;
use App\Models\DomainRegistrarConnection;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A stored secret never follows its connection somewhere else by itself (security audit SA-4, SA-6): the SMTP server or
 * a registrar connection that moves (address, mode) needs its secret typed again.
 */
class SecretsWhenMovedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Http::preventStrayRequests();
        Setting::query()->updateOrCreate(['key' => 'mail_password', 'settingable_type' => null], ['value' => 's3cret-smtp', 'type' => 'string', 'encrypted' => true]);
        Setting::query()->updateOrCreate(['key' => 'mail_host', 'settingable_type' => null], ['value' => 'smtp.ours.test', 'type' => 'string']);
        Setting::query()->updateOrCreate(['key' => 'system_email_address', 'settingable_type' => null], ['value' => 'billing@example.test', 'type' => 'string']);
        config(['settings.system_email_address' => 'billing@example.test']);
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function storedMail(string $key): ?string
    {
        return Setting::query()->whereNull('settingable_type')->where('key', $key)->first()?->value;
    }

    public function test_a_new_smtp_host_needs_its_password_again(): void
    {
        $staff = $this->staff(['admin.settings.view', 'admin.settings.update']);

        $this->livewireAs($staff)->test(Settings::class)->set('data.mail_host', 'smtp.attacker.test')->call('save')
            ->assertHasErrors(['data.mail_password']);
        $this->assertSame('smtp.ours.test', $this->storedMail('mail_host'));

        $this->livewireAs($staff)->test(Settings::class)->set('data.mail_host', 'smtp.new.test')->set('data.mail_password', 'typed-again')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(['smtp.new.test', 'typed-again'], [$this->storedMail('mail_host'), $this->storedMail('mail_password')]);
    }

    public function test_other_mail_settings_save_as_before(): void
    {
        $this->livewireAs($this->staff(['admin.settings.view', 'admin.settings.update']))->test(Settings::class)
            ->set('data.mail_from_name', 'Billing')->call('save')->assertHasNoErrors();

        $this->assertSame('s3cret-smtp', $this->storedMail('mail_password'));
    }

    public function test_the_app_url_is_full_admins_only(): void
    {
        $before = $this->storedMail('app_url');
        $this->livewireAs($this->staff(['admin.settings.view', 'admin.settings.update']))->test(Settings::class)
            ->set('data.app_url', 'https://evil.example')->call('save');

        $this->assertNotSame('https://evil.example', $this->storedMail('app_url') ?? $before);
    }

    public function test_a_registrar_connection_switched_to_sandbox_needs_its_password_again(): void
    {
        $staff = $this->staff(['*']);
        $this->livewireAs($staff)->test(CreateDomainRegistrarConnection::class)
            ->fillForm(['registrar' => 'openprovider', 'label' => 'Openprovider', 'mode' => 'production', 'is_active' => true,
                'credentials' => ['username' => 'reseller-user', 'password' => 'Live-Pa55']])
            ->call('create')->assertHasNoFormErrors();
        $connection = DomainRegistrarConnection::query()->sole();

        $this->livewireAs($staff)->test(EditDomainRegistrarConnection::class, ['record' => $connection->getRouteKey()])
            ->fillForm(['mode' => 'sandbox', 'credentials' => ['username' => 'reseller-user', 'password' => '']])->call('save')
            ->assertHasFormErrors(['credentials.password']);
        $this->assertSame('production', $connection->fresh()->mode);

        // The label alone: as before.
        $this->livewireAs($staff)->test(EditDomainRegistrarConnection::class, ['record' => $connection->getRouteKey()])
            ->fillForm(['label' => 'OP live', 'credentials' => ['username' => 'reseller-user', 'password' => '']])->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Live-Pa55', $connection->fresh()->credentials()['password']);
    }
}
