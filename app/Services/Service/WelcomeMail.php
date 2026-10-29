<?php

namespace App\Services\Service;

use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Models\EmailLog;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * WHMCS's «Resend Welcome Email» (owner 2026-10-29): the service's welcome mail again, with the password stored on it
 * now. The same mail as on activation (NotificationHelper::serverCreatedNotification): the same template, the account's
 * owner only (no CC/BCC: a private template), and the email log without the password. Staff never see it here.
 */
class WelcomeMail
{
    public const TEMPLATE = 'new_server_created';

    /** At most this many an hour per service, staff's resends and «send it with the new password» together. */
    public const PER_HOUR = 3;

    /** Only an active service: the mail says «your service is active», and a suspended one cannot be signed into. */
    public const STATUSES = [Service::STATUS_ACTIVE];

    /** Why it cannot be sent now, or null. */
    public static function refusal(Service $service): ?string
    {
        if (!in_array($service->status, self::STATUSES, true)) {
            return __('Only an active service gets its welcome email again.');
        }
        $owner = $service->client?->owner();
        if ($owner === null) {
            return __('The account has no owner to send it to.');
        }
        $template = NotificationTemplate::query()->where('key', self::TEMPLATE)->first();
        if ($template === null || !$template->enabled || config('settings.mail_disable')) {
            return __('The welcome email is disabled.');
        }
        $preference = $owner->notificationsPreferences()->where('notification_template_id', $template->id)->first();
        if (!$template->isEnabledForPreference($preference, 'mail')) {
            return __('The customer has turned this email off.');
        }
        $password = self::password($service);
        // A login without its password would tell the customer nothing new (an imported service before its passwords
        // were decrypted, docs/importer.md).
        if (filled($service->username) && $password === null) {
            return __('No password is stored on this service: change it on the server or save the one set elsewhere first.');
        }
        // Nothing of the customer's in it: no login, no license (an SSL order, a product without a module).
        if (blank($service->username) && $password === null && $service->license === null) {
            return __('This service has no login details or license to send.');
        }

        return null;
    }

    /** The password stored now, or null (none, or one that does not decrypt). */
    public static function password(Service $service): ?string
    {
        $password = rescue(fn () => $service->password, null, false);

        return is_string($password) && $password !== '' ? $password : null;
    }

    /** Who it goes to (the account's owner), for staff to confirm. */
    public static function recipient(Service $service): ?string
    {
        return $service->client?->owner()?->email;
    }

    public static function limiterKey(Service $service): string
    {
        return 'welcome-resend:' . $service->id;
    }

    /**
     * Sent again, audited (who, to whom, the email log). Refused (DisplayException) when it cannot go, when it went
     * too often this hour, or when the mail was not made (a template that does not render: reported).
     */
    public static function resend(Service $service, Staff $staff): EmailLog
    {
        $service = $service->fresh() ?? throw new DisplayException(__('The service no longer exists.'));
        if (($why = self::refusal($service)) !== null) {
            throw new DisplayException($why);
        }
        if (RateLimiter::tooManyAttempts($key = self::limiterKey($service), self::PER_HOUR)) {
            throw new DisplayException(__('The welcome email was sent :count times in the last hour: try again later.', ['count' => self::PER_HOUR]));
        }
        RateLimiter::hit($key, 3600);

        $last = (int) EmailLog::query()->max('id');
        NotificationHelper::serverCreatedNotification($service->client, $service);
        /** @var User $owner */
        $owner = $service->client->owner();
        $log = EmailLog::query()->where('id', '>', $last)->where('client_id', $service->client_id)
            ->where('template_key', self::TEMPLATE)->where('to', $owner->email)->latest('id')->first();
        if ($log === null) {
            throw new DisplayException(__('The welcome email was not sent: its template could not be rendered (see the error log).'));
        }

        $service->auditEvent = 'welcome_resent';
        $service->isCustomEvent = true;
        $service->auditCustomOld = [];
        $service->auditCustomNew = ['staff_id' => $staff->id, 'to' => $log->to, 'email_log_id' => $log->id];
        event(new AuditCustom($service));

        return $log;
    }
}
