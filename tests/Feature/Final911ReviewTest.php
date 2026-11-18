<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Livewire\Client\Emails;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\EmailLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Final review of #911 (uncommitted probes). */
class Final911ReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->client = $this->owner->client();
    }

    private function log(string $subject, string $key): EmailLog
    {
        return EmailLog::query()->create(['client_id' => $this->client->id, 'subject' => $subject, 'to' => 'owner@example.test',
            'body' => "<p>{$subject} body</p>", 'status' => EmailLog::STATUS_SENT, 'sent_at' => now(), 'template_key' => $key]);
    }

    public function test_a_member_with_every_kind_but_affiliates_sees_the_imported_history(): void
    {
        $this->log('Imported WHMCS invoice', 'whmcs_history');
        $member = User::factory()->withoutClient()->create(['email' => 'member@example.test']);
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $member->id, 'owner' => false,
            'permissions' => ['emails', 'invoices', 'tickets', 'products', 'domains'], 'granted_at' => now()]);
        app()->forgetInstance(CurrentClient::class);
        $this->actingAs($member)->withSession($this->loginUser($member, [CurrentClient::SESSION_KEY => $this->client->id]));

        Livewire::test(Emails::class)->assertSee('Imported WHMCS invoice');
    }

    public function test_support_staff_logged_in_as_the_owner_see_the_owners_mails(): void
    {
        $this->log('Imported WHMCS invoice', 'whmcs_history');
        $this->log('Your ticket copy', 'ticket_cc_message');
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.tickets.viewAny', 'admin.invoices.viewAny'])->create();
        app()->forgetInstance(CurrentClient::class);
        $this->actingAs($staff, 'staff');
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, ['impersonating' => true,
            CurrentClient::IMPERSONATING_KEY => $this->client->id]));

        Livewire::test(Emails::class)->assertSee('Your ticket copy')->assertSee('Imported WHMCS invoice');
    }

    public function test_holds_staff_with_settings_alone_still_save_other_settings_with_mail_on(): void
    {
        Setting::query()->updateOrCreate(['key' => 'mail_password', 'settingable_type' => null], ['value' => 'pw', 'type' => 'string', 'encrypted' => true]);
        config(['settings.mail_disable' => false, 'settings.mail_host' => 'smtp.example.test', 'settings.mail_port' => '587',
            'settings.mail_username' => 'u', 'settings.mail_from_address' => 'from@example.test', 'settings.mail_from_name' => 'MyCS',
            'settings.mail_header' => 'H', 'settings.mail_footer' => 'F', 'settings.mail_css' => 'c', 'settings.mail_encryption' => 'tls']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Settings', 'permissions' => ['admin.settings.view', 'admin.settings.update']])->id]);

        $this->livewireAs($staff)->test(Settings::class)
            ->set('data.system_email_address', 'billing@example.test')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('billing@example.test', Setting::query()->where('key', 'system_email_address')->whereNull('settingable_type')->value('value'));
    }

    public function test_holds_products_staff_still_save_a_product_with_a_snippet(): void
    {
        config(['settings.default_currency' => 'USD']);
        $product = $this->createProduct()->product;
        DB::table('products')->where('id', $product->id)->update(['email_template' => 'Port: {{ centova_port']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.products.viewAny', 'admin.products.update'])->create();

        $this->livewireAs($staff)->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->set('data.name', 'Renamed')->call('save')->assertHasNoFormErrors();
        $this->assertSame('Renamed', $product->fresh()->name);
    }
}
