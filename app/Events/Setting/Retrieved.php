<?php

namespace App\Events\Setting;

use App\Models\Setting;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class Retrieved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(Setting $setting)
    {
        $setting->value = Setting::decodeValue($setting->value, $setting->type, (bool) $setting->encrypted);
    }
}
