<?php

namespace App\Listeners;

use App\Events\Setting\Saved;
use App\Models\Setting;
use App\Providers\SettingsProvider;

class FlushCacheListener
{
    public function handle(Saved $event)
    {
        // A counter moves on every invoice: flushing (and restarting the queue workers, SettingsProvider::flushCache)
        // each time is what EVT-13 found. Nothing reads it from the cache.
        if ($event->setting->settingable_type !== null || in_array($event->setting->key, Setting::COUNTERS, true)) {
            return;
        }
        static $flushed = false;
        if (!$flushed) {
            app()->terminating(fn () => SettingsProvider::flushCache());
            $flushed = true;
        }
    }
}
