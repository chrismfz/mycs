<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Gateway;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Admin\ClientSummary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The client Summary shows the settings that sat only on its Edit page (the owner, 2026-10-24): the «Billing» and
 * «Fiscal invoicing (Ekdosi)» choices, the details' tax and mail fields, the owner's 2FA and its contacts.
 */
class ClientSummarySettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Settings']);
        $this->client = $this->user->client();
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function agreement(Client $client, string $name): BillingAgreement
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'SummaryPay'], ['name' => 'SummaryPay', 'type' => 'gateway']);

        return BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => $name,
            'external_reference' => 'pm_' . uniqid(), 'type' => 'card']);
    }

    private function page(array $permissions = ['*']): TestResponse
    {
        return $this->actingAs($this->staff($permissions))->get(ClientResource::getUrl('view', ['record' => $this->client]))->assertOk();
    }

    /** The HTML of one marked row of the page (`data-setting`), up to the end of its row. */
    private function settingRow(string $html, string $field): string
    {
        $start = strpos($html, 'data-setting="' . $field . '"');
        $this->assertNotFalse($start, $field);

        return substr($html, $start, strpos($html, '</div>', $start) - $start);
    }

    public function test_a_new_client_has_every_setting_at_its_default(): void
    {
        $settings = ClientSummary::for($this->client->fresh(), $this->staff(['*']))['settings'];

        foreach (ClientSummary::SETTING_DEFAULTS as $field => $default) {
            $this->assertSame($default, $settings[$field], $field);
        }
        $this->assertNull($settings['language']);
        $this->assertNull($settings['group']);
        $this->assertNull($settings['method']);

        // Nothing changed, nothing highlighted: no badge in any of the yes/no rows.
        $html = $this->page()->assertSee(__('Billing settings'))->assertSee(__('Fiscal invoicing (Ekdosi)'))->getContent();
        foreach (array_keys(ClientSummary::SETTING_DEFAULTS) as $field) {
            $this->assertStringNotContainsString('fi-badge', $this->settingRow($html, $field), $field);
        }
    }

    public function test_the_billing_and_ekdosi_choices_changed_on_edit_show_on_the_summary(): void
    {
        $this->storeSettings(['allowed_languages' => ['en', 'el']]);
        $group = ClientGroup::query()->create(['name' => 'Resellers', 'colour' => '#7c3aed', 'discount_percent' => 10]);
        $agreement = $this->agreement($this->client, 'Visa **** 4242');
        $this->client->forceFill(['language' => 'el', 'client_group_id' => $group->id, 'default_billing_agreement_id' => $agreement->id,
            'auto_charge' => false, 'credit_auto_apply' => false, 'separate_invoices' => true, 'tax_exempt' => true,
            'dunning_exempt' => true, 'no_overdue_reminders' => true,
            'ignore_invoicing' => true, 'immediate_invoicing' => true])->save();

        $settings = ClientSummary::for($this->client->fresh(), $this->staff(['*']))['settings'];

        foreach (ClientSummary::SETTING_DEFAULTS as $field => $default) {
            $this->assertSame(!$default, $settings[$field], $field);
        }
        $this->assertSame('Ελληνικά', $settings['language']);
        $this->assertSame(['name' => 'Resellers', 'percent' => '10.00', 'colour' => '#7c3aed'], $settings['group']);
        $this->assertSame('Visa **** 4242', $settings['method']);

        $html = $this->page()->assertSee('Ελληνικά')->assertSee('Resellers −10%')->assertSee('Visa **** 4242')
            ->assertSee('background-color: #7c3aed', false)->getContent();
        foreach (array_keys(ClientSummary::SETTING_DEFAULTS) as $field) {
            $this->assertStringContainsString('fi-badge', $this->settingRow($html, $field), $field);
        }
    }

    public function test_another_accounts_method_is_no_method_as_the_cron_reads_it(): void
    {
        $other = User::factory()->create()->client();
        $foreign = $this->agreement($other, 'Foreign card');
        DB::table('clients')->where('id', $this->client->id)->update(['default_billing_agreement_id' => $foreign->id]);

        $this->assertNull(ClientSummary::for($this->client->fresh(), $this->staff(['*']))['settings']['method']);
        $this->page()->assertDontSee('Foreign card');
    }

    public function test_a_group_colour_that_is_not_a_hex_colour_is_left_out_of_the_style(): void
    {
        $group = ClientGroup::query()->create(['name' => 'Odd', 'discount_percent' => 0]);
        DB::table('client_groups')->where('id', $group->id)->update(['colour' => 'red;x:1']);
        $this->client->forceFill(['client_group_id' => $group->id])->save();

        $settings = ClientSummary::for($this->client->fresh(), $this->staff(['*']))['settings'];

        $this->assertSame(['name' => 'Odd', 'percent' => null, 'colour' => null], $settings['group']);
        $this->page()->assertSee('Odd')->assertDontSee('red;x:1', false);
    }

    public function test_the_details_show_the_tax_document_and_mail_choices(): void
    {
        $this->client->primaryContact->forceFill(['type' => 'business', 'company_name' => 'Example Ltd', 'tax_office' => 'Serres',
            'activity' => 'Web hosting', 'document_type' => 'invoice', 'email_preferences' => ['general', 'invoice', 'support', 'product', 'domain']])->save();
        DB::table('contacts')->where('id', $this->client->primaryContact->id)->update(['vat_number' => 'EL123456789', 'vat_status' => 'valid']);
        $this->client->contacts()->create(['first_name' => 'Second', 'last_name' => 'Contact']);
        $archived = $this->client->contacts()->create(['first_name' => 'Gone', 'last_name' => 'Contact']);
        DB::table('contacts')->where('id', $archived->id)->update(['archived_at' => now()]);

        $summary = ClientSummary::for($this->client->fresh(), $this->staff(['*']));

        $this->assertSame('business', $summary['details']['type']);
        $this->assertSame('valid', $summary['details']['vat_status']);
        $this->assertSame(['general', 'invoice', 'support', 'product', 'domain'], $summary['details']['email_preferences']);
        $this->assertSame(2, $summary['other']['contacts']); // the archived one is not counted

        $this->page()->assertSee(__('account.contact_type.business'))->assertSee('Serres')->assertSee('Web hosting')
            ->assertSee(__('account.document_type.invoice'))->assertSee(__('vat.status.valid'))
            ->assertSee(__('Not: :list', ['list' => __('account.email_preference.affiliate')]));
    }

    public function test_every_mail_category_ticked_reads_as_all(): void
    {
        $this->client->primaryContact->forceFill(['email_preferences' => ['affiliate', 'domain', 'product', 'support', 'invoice', 'general']])->save();

        $row = $this->settingRow($this->page()->getContent(), 'email_preferences');
        $this->assertStringContainsString(__('All'), $row);
        $this->assertStringNotContainsString('fi-badge', $row);
    }

    public function test_an_empty_mail_choice_reads_as_none_and_never_chosen_as_all(): void
    {
        // Sending reads the choice now (EmailKinds, owner 2026-10-30): [] is no mail but the ones that always go, a warning.
        $this->client->primaryContact->forceFill(['email_preferences' => []])->save();

        $this->assertSame([], ClientSummary::for($this->client->fresh(), $this->staff(['*']))['details']['email_preferences']);
        $row = $this->settingRow($this->page()->getContent(), 'email_preferences');
        $this->assertStringContainsString(__('account.email_preferences_none'), $row);
        $this->assertStringContainsString('fi-badge', $row);

        $this->client->primaryContact->forceFill(['email_preferences' => null])->save();
        $this->assertNull(ClientSummary::for($this->client->fresh(), $this->staff(['*']))['details']['email_preferences']);
        $row = $this->settingRow($this->page()->getContent(), 'email_preferences');
        $this->assertStringContainsString(__('All'), $row);
        $this->assertStringNotContainsString('fi-badge', $row);
    }

    public function test_the_instances_own_language_chosen_by_hand_is_the_default(): void
    {
        $this->storeSettings(['allowed_languages' => ['en', 'el'], 'app_language' => 'el']);
        $this->client->forceFill(['language' => 'el'])->save(); // as WHMCS «greek» is imported

        $settings = ClientSummary::for($this->client->fresh(), $this->staff(['*']))['settings'];

        $this->assertNull($settings['language']);
        $this->assertSame('Ελληνικά', $settings['language_default']);
        $this->assertStringNotContainsString('fi-badge', $this->settingRow($this->page()->getContent(), 'language'));
    }

    public function test_auto_charge_without_a_method_says_so(): void
    {
        $row = $this->settingRow($this->page()->getContent(), 'auto_charge');
        $this->assertStringContainsString(__('Yes, no method saved'), $row);
        $this->assertStringNotContainsString('fi-badge', $row);

        $this->client->forceFill(['default_billing_agreement_id' => $this->agreement($this->client, 'Visa 1111')->id])->save();
        $this->assertStringNotContainsString(__('Yes, no method saved'), $this->settingRow($this->page()->getContent(), 'auto_charge'));
    }

    public function test_the_owners_2fa_shows_only_to_staff_who_may_see_logins(): void
    {
        $this->user->forceFill(['tfa_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        $this->assertTrue(ClientSummary::for($this->client->fresh(), $this->staff(['admin.users.viewAny']))['other']['owner_2fa']);
        $this->assertNull(ClientSummary::for($this->client->fresh(), $this->staff(['admin.clients.viewAny']))['other']['owner_2fa']);
        $this->page()->assertSee(__('Owner 2FA'));
        $this->page(['admin.clients.viewAny'])->assertDontSee(__('Owner 2FA'));
    }

    public function test_staff_who_may_only_see_clients_see_the_settings_but_no_money(): void
    {
        $this->client->forceFill(['immediate_invoicing' => true])->save();

        $this->page(['admin.clients.viewAny'])->assertSee(__('Billing settings'))->assertSee(__('Immediate invoicing'))
            ->assertDontSee(__('Credit Balance'))->assertDontSee(ClientResource::getUrl('edit', ['record' => $this->client]));
    }
}
