<?php

namespace Tests\Feature;

use App\Classes\FilamentInput;
use Filament\Facades\Filament;
use Filament\Forms\Components\TimePicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #399. `DateTimePicker::configureUsing()` reaches every subclass (Filament configures the whole
 * class hierarchy), so the TimePicker of the Settings page («cronjob_time») is given `displayFormat('d/m/Y H:i')`:
 * a time-only field that shows a date.
 */
class AdminCompactFormsReviewTest extends TestCase
{
    use RefreshDatabase;

    private function bootAdmin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
    }

    public function test_a_time_picker_shows_a_time_not_a_date(): void
    {
        $this->bootAdmin();

        $format = TimePicker::make('at')->getDisplayFormat();

        $this->assertStringNotContainsString('d/m/Y', $format, "a TimePicker displays '{$format}'");
        $this->assertStringContainsString('H:i', $format);
    }

    public function test_the_settings_cron_time_shows_a_time(): void
    {
        $this->bootAdmin();

        $field = FilamentInput::convert((object) ['name' => 'cronjob_time', 'type' => 'time', 'label' => 'Cron time']);

        $this->assertInstanceOf(TimePicker::class, $field);
        $this->assertStringNotContainsString('d/m/Y', $field->getDisplayFormat());
    }
}
