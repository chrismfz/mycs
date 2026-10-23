<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * One sign-in, good or not, to the client area or the admin (Login History, owner 2026-10-23). The address is kept as
 * typed only when it is an address (a password typed into it never is); the password never. Kept 90 days.
 *
 * @property int $id
 * @property string $area
 * @property int|null $user_id
 * @property int|null $staff_id
 * @property string|null $email
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $result
 */
class LoginAttempt extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const CLIENT = 'client';

    public const STAFF = 'staff';

    public const SUCCESS = 'success';

    /** A session that came back by «Remember me»: no password asked. */
    public const REMEMBERED = 'remembered';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    public const FAILED_2FA = 'failed_2fa';

    public const THROTTLED = 'throttled';

    public const RESULTS = [
        self::SUCCESS => 'Success',
        self::REMEMBERED => 'Remembered',
        self::FAILED => 'Wrong password',
        self::UNKNOWN => 'Unknown address',
        self::FAILED_2FA => 'Wrong 2FA code',
        self::THROTTLED => 'Too many attempts',
    ];

    public const DAYS = 90;

    protected $fillable = ['area', 'user_id', 'staff_id', 'email', 'ip_address', 'user_agent', 'result'];

    /** A sign-in in the history; never the sign-in's own failure. */
    public static function record(string $area, User|Staff|null $account, ?string $email, string $result): void
    {
        try {
            $email = $email === null ? null : trim($email);
            self::query()->create([
                'area' => $area,
                'user_id' => $account instanceof User ? $account->id : null,
                'staff_id' => $account instanceof Staff ? $account->id : null,
                'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 191) : null,
                'ip_address' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 255) ?: null,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::DAYS));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
