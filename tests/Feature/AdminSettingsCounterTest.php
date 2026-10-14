<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Settings page writes back only what the staff changed (audit sweep KR-4, INV-1). It used to write every value
 * that differed from the database: open the page, let checkout/cron issue invoices, change anything, save, and the
 * invoice counter went back to what the page had loaded (the next number collides; on nixpal, numbered on payment,
 * the payment rolls back after the gateway charged).
 */
class AdminSettingsCounterTest extends TestCase
{
    use RefreshDatabase;

    private Staff $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $this->counters(100, 7);
        config(['settings.system_email_address' => 'billing@example.test']); // required, empty in the test seed
    }

    private function counters(int $invoice, int $creditNote): void
    {
        foreach (['invoice_number' => $invoice, 'credit_note_number' => $creditNote] as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value]);
            config(["settings.$key" => $value]);
        }
    }

    /** An invoice with this number (the listener numbers it on create; the number under test is set after). */
    private function issued(string $number): void
    {
        $counter = $this->value('invoice_number');
        Invoice::factory()->create(['user_id' => User::factory()->create()->id])->forceFill(['number' => $number])->saveQuietly();
        Setting::query()->where('key', 'invoice_number')->whereNull('settingable_type')->update(['value' => $counter]);
        config(['settings.invoice_number' => $counter]); // the listener's save moved config too
    }

    private function value(string $key): ?string
    {
        return Setting::query()->where('key', $key)->whereNull('settingable_type')->value('value');
    }

    public function test_saving_another_setting_does_not_rewind_the_counters_moved_on_meanwhile(): void
    {
        $page = $this->livewireAs($this->owner)->test(Settings::class);

        // Checkout and the cron issue invoices and notes while the page is open.
        Setting::query()->where('key', 'invoice_number')->update(['value' => 105]);
        Setting::query()->where('key', 'credit_note_number')->update(['value' => 9]);

        $page->set('data.app_language', 'el')->call('save')->assertHasNoErrors();

        $this->assertSame('el', $this->value('app_language'));
        $this->assertSame('105', $this->value('invoice_number'));
        $this->assertSame('9', $this->value('credit_note_number'));
    }

    public function test_staff_can_move_a_counter_forward(): void
    {
        $this->livewireAs($this->owner)->test(Settings::class)
            ->set('data.invoice_number', 32476)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('32476', $this->value('invoice_number'));
    }

    public function test_a_counter_cannot_go_back_onto_numbers_already_used_and_nothing_is_saved(): void
    {
        config(['settings.invoice_number_format' => 'INV-{number}', 'settings.invoice_number_padding' => 1]);
        $this->issued('INV-102'); // 101..105 issued
        $page = $this->livewireAs($this->owner)->test(Settings::class);
        Setting::query()->where('key', 'invoice_number')->update(['value' => 105]);

        $page->set('data.invoice_number', 101)
            ->set('data.app_language', 'el')
            ->call('save')
            ->assertHasErrors('data.invoice_number');

        $this->assertSame('105', $this->value('invoice_number'));
        $this->assertNotSame('el', $this->value('app_language'));
    }

    public function test_a_counter_may_restart_when_the_next_number_is_free(): void
    {
        // {YEAR}-{NUMBER} (nixpal): on 1 January the count restarts, 2027-1 is free although 2026-150 was the last.
        config(['settings.invoice_number_format' => '{year}-{number}', 'settings.invoice_number_padding' => 1]);
        $this->issued(now()->subYear()->format('Y') . '-150');
        Setting::query()->where('key', 'invoice_number')->update(['value' => 150]);
        config(['settings.invoice_number' => 150]);

        $this->livewireAs($this->owner)->test(Settings::class)
            ->set('data.invoice_number', 0)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('0', $this->value('invoice_number'));
    }

    public function test_a_counter_is_a_whole_number_in_range(): void
    {
        foreach (['99999999999999999999', '12.5', '-1', 'abc'] as $bad) {
            $this->livewireAs($this->owner)->test(Settings::class)
                ->set('data.invoice_number', $bad)
                ->call('save')
                ->assertHasErrors('data.invoice_number');
        }

        $this->assertSame('100', $this->value('invoice_number'));
    }

    public function test_a_second_save_from_the_same_page_keeps_the_staff_value_and_later_numbers(): void
    {
        $page = $this->livewireAs($this->owner)->test(Settings::class)
            ->set('data.invoice_number', 200)
            ->call('save')
            ->assertHasNoErrors();
        Setting::query()->where('key', 'invoice_number')->update(['value' => 203]);

        $page->set('data.app_language', 'el')->call('save')->assertHasNoErrors();

        $this->assertSame('203', $this->value('invoice_number'));
    }
}
