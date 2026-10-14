<?php

use App\Helpers\EventHelper;

if (!function_exists('theme')) {
    /**
     * A setting of the client area's look (the Appearance tab, stored as theme_<key>).
     */
    function theme(string $key, mixed $default = null): mixed
    {
        return config("settings.theme_{$key}", $default) ?? $default;
    }
}

if (!function_exists('hook')) {
    /**
     * Dispatch an event and return the items
     *
     * @param  string  $event
     * @param  array  $items
     * @return array
     */
    function hook($event)
    {
        return EventHelper::renderEvent($event);
    }
}
