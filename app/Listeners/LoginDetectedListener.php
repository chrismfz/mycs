<?php

namespace App\Listeners;

use App\Events\Auth\Login;
use App\Helpers\NotificationHelper;
use App\Models\Session;
use App\Models\UserAuthenticationLog;

/** A login from an address not seen before is mailed. Not queued: it reads the request (address, device). */
class LoginDetectedListener
{
    public function handle(Login $event): void
    {
        $user = $event->user;
        $ip = request()->ip();
        if (!$user->authenticationLogs()->where('ip_address', $ip)->exists()) {
            // If the log for this IP does not exist, create a new log
            $log = new UserAuthenticationLog;
            $log->user_id = $user->id;
            $log->ip_address = $ip;
            $log->save();

            $data = [
                'ip' => $ip,
                'device' => (new Session(['user_agent' => request()->userAgent()]))->getFormattedDeviceAttribute(),
                'time' => now()->format('d/m/Y H:i'),
            ];
            NotificationHelper::loginDetectedNotification($user, $data);
        } else {
            // If it exists, update the last used timestamp
            $log = $user->authenticationLogs()->where('ip_address', $ip)->first();
            $log->last_used_at = now();
            $log->save();
        }
    }
}
