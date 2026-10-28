<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The languages kept (owner 2026-10-24), and a migration that forgets the dropped ones wherever they were chosen. */
class DroppedLanguagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_kept_languages_remain(): void
    {
        $dirs = array_map('basename', glob(base_path('lang/*'), GLOB_ONLYDIR));
        sort($dirs);

        $this->assertSame(['da', 'de', 'el', 'en', 'es', 'fr', 'nl', 'pt', 'sv'], $dirs);
        $this->assertSame($dirs, array_keys(config('app.available_locales')));
    }

    public function test_a_session_holding_a_dropped_language_forgets_it(): void
    {
        $this->withSession(['locale' => 'zh'])->get('/')->assertOk();

        $this->assertNotSame('zh', app()->getLocale());
        $this->assertFalse(session()->has('locale'));
    }
}
