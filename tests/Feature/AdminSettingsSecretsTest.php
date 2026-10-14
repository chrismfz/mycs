<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Settings page never sends a secret to the browser (audit sweep AUTH-12): it filled the form with every value,
 * the SMTP and IMAP passwords included, so staff with settings.view only could read them in the page state.
 */
class AdminSettingsSecretsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Setting::query()->updateOrCreate(['key' => 'mail_password', 'settingable_type' => null], ['value' => 's3cret-smtp', 'type' => 'string', 'encrypted' => true]);
        // Mail on, so the stored password would be required: the rest of the mail settings as an instance has them.
        config(['settings.mail_password' => 's3cret-smtp', 'settings.mail_disable' => false, 'settings.system_email_address' => 'billing@example.test',
            'settings.mail_host' => 'smtp.example.test', 'settings.mail_port' => 587, 'settings.mail_username' => 'billing',
            'settings.mail_from_address' => 'billing@example.test', 'settings.mail_from_name' => 'Billing',
            'settings.mail_header' => '<p>Header</p>', 'settings.mail_footer' => '<p>Footer</p>', 'settings.mail_css' => 'p {}']);
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Staff ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function stored(): ?string
    {
        $row = Setting::query()->where('key', 'mail_password')->whereNull('settingable_type')->first();

        return $row?->value; // decrypted by the model
    }

    public function test_view_only_staff_do_not_get_the_secret_in_the_page(): void
    {
        $page = $this->livewireAs($this->staff(['admin.settings.view']))->test(Settings::class);

        $this->assertEmpty($page->get('data.mail_password'));
        $this->assertStringNotContainsString('s3cret-smtp', json_encode($page->snapshot));
        $this->assertStringNotContainsString('s3cret-smtp', $page->html());
    }

    public function test_saving_with_the_secret_left_empty_keeps_it(): void
    {
        $this->livewireAs($this->staff(['*']))->test(Settings::class)
            ->set('data.app_language', 'el')
            ->call('save')
            ->assertHasNoErrors(); // not required again while one is stored

        $this->assertSame('s3cret-smtp', $this->stored());
        $this->assertSame('el', Setting::query()->where('key', 'app_language')->whereNull('settingable_type')->value('value'));
    }

    public function test_a_new_secret_typed_in_is_saved_encrypted(): void
    {
        $this->livewireAs($this->staff(['*']))->test(Settings::class)
            ->set('data.mail_password', 'n3w-pass')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('n3w-pass', $this->stored());
        $raw = Setting::query()->where('key', 'mail_password')->whereNull('settingable_type')->toBase()->value('value');
        $this->assertNotSame('n3w-pass', $raw);
        $this->assertSame('n3w-pass', Crypt::decryptString($raw));
    }

    public function test_after_a_save_the_secret_is_not_in_the_page_either(): void
    {
        $page = $this->livewireAs($this->staff(['*']))->test(Settings::class)
            ->set('data.app_language', 'el')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertStringNotContainsString('s3cret-smtp', json_encode($page->snapshot));
    }

    public function test_a_secret_typed_in_leaves_the_page_once_saved(): void
    {
        $page = $this->livewireAs($this->staff(['*']))->test(Settings::class)
            ->set('data.mail_password', 'n3w-pass')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('n3w-pass', $this->stored());
        $this->assertStringNotContainsString('n3w-pass', json_encode($page->snapshot));
    }

    public function test_a_secret_is_still_required_while_none_is_stored(): void
    {
        Setting::query()->where('key', 'mail_password')->delete();
        config(['settings.mail_password' => null]);

        $this->livewireAs($this->staff(['*']))->test(Settings::class)
            ->set('data.app_language', 'el')
            ->call('save')
            ->assertHasErrors('data.mail_password');
    }
}
