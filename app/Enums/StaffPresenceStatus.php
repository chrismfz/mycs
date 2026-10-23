<?php

namespace App\Enums;

/**
 * What a member of staff says of themselves (docs/livechat.md §5α, §7β): set by hand only, never changed by the app,
 * and kept as they left it at the next login.
 */
enum StaffPresenceStatus: string
{
    case Available = 'available';
    case Busy = 'busy';
    case Away = 'away';

    public function label(): string
    {
        return match ($this) {
            self::Available => __('Available'),
            self::Busy => __('Busy'),
            self::Away => __('Away'),
        };
    }

    /** The dot beside the name: green, amber, grey. */
    public function color(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Busy => 'warning',
            self::Away => 'gray',
        };
    }
}
