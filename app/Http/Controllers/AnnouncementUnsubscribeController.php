<?php

namespace App\Http\Controllers;

use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * «Stop these emails» of a mass mail's news (BulkMailer): its signed link, for that login only. Opened, it asks; the
 * button turns news off (the same preference as the account's Notifications page). Important announcements are not
 * news: they are still sent.
 */
class AnnouncementUnsubscribeController extends Controller
{
    public function show(Request $request, User $user): Response
    {
        return response()->view('announcements.unsubscribe', ['done' => false, 'action' => $request->fullUrl()]);
    }

    public function store(User $user): Response
    {
        $template = NotificationTemplate::query()->where('key', 'announcement_news')->first();
        if ($template !== null) {
            $user->notificationsPreferences()->updateOrCreate(['notification_template_id' => $template->id], ['mail_enabled' => false, 'in_app_enabled' => false]);
        }

        return response()->view('announcements.unsubscribe', ['done' => true, 'action' => null]);
    }
}
