<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Listeners\InvoiceNumberListener;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of PR #48 (KR-4): the Settings page writes only what staff changed, counters never go back.
 * test_holds_* are edge cases that pass; the others are findings and fail on the PR head.
 */
class AdminSettingsCounterAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->owner = User::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        config(['settings.system_email_address' => 'billing@example.test']);
        config(['settings.invoice_number_format' => 'INV-{number}', 'settings.invoice_number_padding' => 1]);
    }

    /** Invoices already issued with these numbers (the page refuses a counter whose next number is taken). */
    private function issued(string ...$numbers): void
    {
        $counter = Setting::query()->where('key', 'invoice_number')->whereNull('settingable_type')->value('value');
        foreach ($numbers as $number) {
            // Created (numbered by the listener, which also moves the counter), then given the number under test.
            Invoice::factory()->create(['user_id' => $this->owner->id])->forceFill(['number' => $number])->saveQuietly();
        }
        Setting::query()->where('key', 'invoice_number')->whereNull('settingable_type')->update(['value' => $counter]);
        config(['settings.invoice_number' => $counter]); // the listener's save moved config too
    }

    /** Stores a global setting row and mirrors it into config, the way SettingsProvider loads it. */
    private function store(string $key, mixed $value, string $type = 'string', bool $encrypted = false): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key, 'settingable_type' => null],
            ['value' => $value, 'type' => $type, 'encrypted' => $encrypted],
        );
        config(["settings.$key" => Setting::query()->where('key', $key)->whereNull('settingable_type')->first()->value]);
    }

    private function value(string $key): mixed
    {
        return Setting::query()->where('key', $key)->whereNull('settingable_type')->first()?->value;
    }

    // ---------------------------------------------------------------- findings

    /**
     * ADV-1. trusted_proxies with no row loads as null but the TagsInput dehydrates to [], so it is "changed" on every
     * save and written back as []: an admin's concurrent change to it is wiped by anyone else's unrelated save,
     * which is what the PR says no longer happens ("two staff changing different fields no longer erase each other").
     */
    public function test_an_unrelated_save_does_not_wipe_trusted_proxies_set_meanwhile(): void
    {
        $page = Livewire::actingAs($this->owner)->test(Settings::class);

        // Another admin (or the CLI) sets trusted proxies while this page is open.
        Setting::query()->create(['key' => 'trusted_proxies', 'value' => ['10.0.0.1'], 'type' => 'array']);

        $page->set('data.app_language', 'el')->call('save')->assertHasNoErrors();

        $this->assertSame(['10.0.0.1'], $this->value('trusted_proxies'));
    }

    /**
     * ADV-2. No upper bound on the counter. Filament's numeric() makes the state a float; (int) of a float past
     * PHP_INT_MAX wraps modulo 2^64, so 99999999999999999999 becomes 7766279631452241920 and passes the "not below the
     * last number" check (while 2^63 wraps negative and gets a misleading "lower value" error). The stored "1.0E+20"
     * reads back in the listener as PHP_INT_MAX; ++ makes it a float and numbering throws for every invoice from then
     * on (checkout, and on proforma the payment), until someone fixes the setting by hand.
     */
    public function test_a_counter_value_that_breaks_numbering_is_rejected(): void
    {
        $this->store('invoice_number', 100);

        Livewire::actingAs($this->owner)->test(Settings::class)
            ->set('data.invoice_number', '99999999999999999999')
            ->call('save')
            ->assertHasErrors('data.invoice_number');
    }

    /** ADV-2 (consequence): what the accepted value does to the next invoice. */
    public function test_numbering_still_works_after_staff_saves_a_huge_counter(): void
    {
        $this->store('invoice_number', 100);
        Livewire::actingAs($this->owner)->test(Settings::class)
            ->set('data.invoice_number', '99999999999999999999')
            ->call('save')
            ->assertHasErrors('data.invoice_number'); // was accepted (float 1.0E+20, (int) wraps); now refused
        $this->assertSame('100', (string) $this->value('invoice_number'));

        $invoice = new Invoice;
        $setNumber = new \ReflectionMethod(InvoiceNumberListener::class, 'setNumber');
        try {
            $setNumber->invoke(new InvoiceNumberListener, $invoice, 'invoice');
        } catch (Throwable $e) {
            $this->fail('Numbering is broken after the save: ' . $e::class . ': ' . $e->getMessage());
        }
        $this->assertNotNull($invoice->number);
    }

    /**
     * ADV-3. Staff reverting a value another admin changed while the page was open: the form shows the old value,
     * staff types it again and saves. The page cannot tell that from an untouched field. Decision (#48): the other
     * admin's value is kept, the page says so and shows the stored value, so what is on screen is what is stored.
     */
    public function test_a_value_changed_meanwhile_is_kept_said_and_shown(): void
    {
        $this->store('invoice_number_format', 'INV-{number}');
        $page = Livewire::actingAs($this->owner)->test(Settings::class);

        Setting::query()->where('key', 'invoice_number_format')->update(['value' => 'X-{number}']);

        $page->set('data.invoice_number_format', 'INV-{number}')->call('save')->assertHasNoErrors()
            ->assertNotified()
            ->assertSet('data.invoice_number_format', 'X-{number}');

        $this->assertSame('X-{number}', $this->value('invoice_number_format'));
    }

    // ---------------------------------------------------------------- holds

    public function test_holds_locked_snapshot_cannot_be_tampered_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->owner)->test(Settings::class)->set('loaded.invoice_number', 999999);
    }

    public function test_holds_view_only_staff_cannot_save(): void
    {
        $viewer = User::factory()->create(['role_id' => Role::create(['name' => 'Viewer', 'permissions' => ['admin.settings.view']])->id]);
        $this->store('invoice_number', 100);

        Livewire::actingAs($viewer)->test(Settings::class)
            ->set('data.invoice_number', 500)
            ->call('save')
            ->assertNotFound(); // Gate::defaultDenialResponse is denyAsNotFound

        $this->assertSame('100', $this->value('invoice_number'));
    }

    public function test_holds_clearing_or_garbage_in_a_counter_is_rejected(): void
    {
        $this->store('invoice_number', 100);
        $this->issued('INV-1'); // so going back to 0 would reuse numbers

        foreach (['', null, '0', '-5', '150abc'] as $bad) {
            Livewire::actingAs($this->owner)->test(Settings::class)
                ->set('data.invoice_number', $bad)
                ->call('save')
                ->assertHasErrors('data.invoice_number');
            $this->assertSame('100', $this->value('invoice_number'), 'input ' . var_export($bad, true));
        }
    }

    public function test_holds_setting_the_counter_equal_to_the_last_number_used(): void
    {
        $this->store('invoice_number', 100);
        $page = Livewire::actingAs($this->owner)->test(Settings::class);
        Setting::query()->where('key', 'invoice_number')->update(['value' => 105]);

        $page->set('data.invoice_number', 105)->call('save')->assertHasNoErrors();

        $this->assertSame(105, (int) $this->value('invoice_number'));
    }

    public function test_holds_counter_without_a_row_is_not_written_when_untouched_and_can_be_created(): void
    {
        Setting::query()->where('key', 'credit_note_number')->delete(); // fresh install: no credit note numbered yet
        config(['settings.credit_note_number' => null]);
        $page = Livewire::actingAs($this->owner)->test(Settings::class);
        $page->set('data.app_language', 'el')->call('save')->assertHasNoErrors();
        $this->assertNull($this->value('credit_note_number'));

        // The app numbers a first credit note meanwhile; the page did not write it, so nothing to rewind.
        Setting::query()->create(['key' => 'credit_note_number', 'value' => 3]);
        // Lower than the last used is refused only when its next number is taken (#48 review: a new {year}
        // restarts the count); no credit note 3 exists here, so 2 is accepted.
        $page->set('data.credit_note_number', 2)->call('save')->assertHasNoErrors();
        $page->set('data.credit_note_number', 10)->call('save')->assertHasNoErrors();
        $this->assertSame('10', (string) $this->value('credit_note_number'));
    }

    public function test_holds_decimal_and_exponent_counters_keep_numbering_monotonic(): void
    {
        $this->store('invoice_number', 100);
        Livewire::actingAs($this->owner)->test(Settings::class)
            ->set('data.invoice_number', '1e3')
            ->call('save');

        $invoice = new Invoice;
        (new \ReflectionMethod(InvoiceNumberListener::class, 'setNumber'))->invoke(new InvoiceNumberListener, $invoice, 'invoice');
        $this->assertGreaterThan(100, (int) preg_replace('/\D/', '', $invoice->number));
    }

    public function test_holds_other_typed_fields_changed_meanwhile_are_not_reverted(): void
    {
        $this->store('invoice_proforma', false, 'boolean');
        $this->store('ticket_departments', ['Support'], 'array');
        $this->store('mail_password', 'old-secret', 'string', true);
        $this->store('cronjob_time', '09:30');
        $this->store('invoice_number_padding', '4');
        $this->store('trusted_proxies', ['10.0.0.1'], 'array');
        $page = Livewire::actingAs($this->owner)->test(Settings::class);

        foreach ([
            'invoice_proforma' => true, 'ticket_departments' => ['Sales'], 'mail_password' => 'new-secret',
            'cronjob_time' => '10:45', 'invoice_number_padding' => '6', 'trusted_proxies' => ['10.0.0.2'],
        ] as $key => $value) {
            Setting::query()->where('key', $key)->whereNull('settingable_type')->first()->update(['value' => $value]);
        }

        $page->set('data.app_language', 'el')->call('save')->assertHasNoErrors();

        $this->assertTrue($this->value('invoice_proforma'));
        $this->assertSame(['Sales'], $this->value('ticket_departments'));
        $this->assertSame('new-secret', $this->value('mail_password'));
        $this->assertSame('10:45', $this->value('cronjob_time'));
        $this->assertSame('6', $this->value('invoice_number_padding'));
        $this->assertSame(['10.0.0.2'], $this->value('trusted_proxies'));
    }

    public function test_holds_an_existing_logo_is_not_rewritten_by_an_unrelated_save(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logo-light.webp', 'x');
        $this->store('logo', 'logo-light.webp');
        $page = Livewire::actingAs($this->owner)->test(Settings::class);

        Setting::query()->where('key', 'logo')->update(['value' => 'logo-new.webp']);
        $page->set('data.app_language', 'el')->call('save')->assertHasNoErrors();

        $this->assertSame('logo-new.webp', $this->value('logo'));
    }

    public function test_holds_validation_error_on_one_field_writes_nothing(): void
    {
        $this->store('invoice_number', 100);

        Livewire::actingAs($this->owner)->test(Settings::class)
            ->set('data.invoice_number', 500)
            ->set('data.invoice_number_format', 'no-placeholder')
            ->call('save')
            ->assertHasErrors('data.invoice_number_format');

        $this->assertSame('100', (string) $this->value('invoice_number'));
    }

    public function test_holds_second_save_after_a_rejected_counter_still_compares_with_the_snapshot(): void
    {
        $this->store('invoice_number', 100);
        $page = Livewire::actingAs($this->owner)->test(Settings::class);
        Setting::query()->where('key', 'invoice_number')->update(['value' => 105]);
        $this->issued('INV-102');

        $page->set('data.invoice_number', 101)->call('save')->assertHasErrors('data.invoice_number');
        // Staff puts the field back to what the page showed and saves something else: the counter is left alone.
        $page->set('data.invoice_number', '100')->set('data.app_language', 'el')->call('save')->assertHasNoErrors();

        $this->assertSame('105', (string) $this->value('invoice_number'));
        $this->assertSame('el', $this->value('app_language'));
    }
}
