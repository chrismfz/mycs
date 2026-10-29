<?php

namespace App\Services\Mail;

use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Helpers\TemplateRenderer;
use App\Jobs\SendBulkMailJob;
use App\Mail\Mail;
use App\Models\BulkMail;
use App\Models\BulkMailRecipient;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use MyCS\MailTemplate\Html;
use Throwable;

/**
 * The mass mail (ROADMAP 6δ, WHMCS's Mass Mail Tool): the accounts its filters find, each mailed once (its recipient row,
 * claimed before it is sent: never twice, a crash leaves it «sending» for staff, never resent), off the request, a
 * batch a minute. Its message is staff's template ({{ user.first_name }}, {{ client.name }}, the accounts' matching
 * {% for service in services %}, {% for domain in domains %}), rendered from data, never compiled; in the account's
 * language when staff wrote it in Greek too. News goes only to those who did not turn it off, with a link to stop it.
 *
 * Filters (BulkMail::filters): target all | services | domains; client_groups; for «all», active_only (an active
 * service or domain); for services: service_statuses (active by default), products, servers; for domains:
 * domain_statuses (active by default), tlds.
 */
final class BulkMailer
{
    public const TARGETS = ['all', 'services', 'domains'];

    /** @return Builder<Client> the accounts it is for (with an owner to mail) */
    public function recipients(BulkMail $mail): Builder
    {
        $filters = $mail->filters ?? [];
        $query = Client::query()->whereHas('ownerGrant');
        if (($groups = array_filter((array) ($filters['client_groups'] ?? []))) !== []) {
            $query->whereIn('client_group_id', $groups);
        }

        return match ($filters['target'] ?? 'all') {
            'services' => $query->whereHas('services', fn (Builder $services) => self::services($services, $filters)),
            'domains' => $query->whereHas('domains', fn (Builder $domains) => self::domains($domains, $filters)),
            default => empty($filters['active_only']) ? $query : $query->where(fn (Builder $any) => $any
                ->whereHas('services', fn (Builder $services) => $services->where('status', Service::STATUS_ACTIVE))
                ->orWhereHas('domains', fn (Builder $domains) => $domains->where('status', 'active'))),
        };
    }

    /**
     * Staff send it: its recipients fixed now (whoever the filters find), then mailed off the request.
     *
     * @return int how many it goes to
     *
     * @throws DisplayException not a draft any more (sent by someone else meanwhile), or no one to send it to
     */
    public function start(BulkMail $mail, ?Staff $staff): int
    {
        try {
            return Cache::lock("bulk-mail:{$mail->id}", 300)->block(10, fn () => $this->startLocked($mail, $staff));
        } catch (LockTimeoutException) {
            throw new DisplayException(__('This mass mail is being sent already.'));
        }
    }

    private function startLocked(BulkMail $mail, ?Staff $staff): int
    {
        $mail->refresh();
        if (!$mail->isDraft()) {
            throw new DisplayException(__('This mass mail has been sent already.'));
        }
        if (!$this->recipients($mail)->exists()) {
            throw new DisplayException(__('No customer matches its filters.'));
        }
        $count = DB::transaction(function () use ($mail, $staff) {
            $people = [];
            $this->recipients($mail)->select('id')->chunkById(500, function (Collection $clients) use ($mail, &$people) {
                $owners = ClientUser::query()->whereIn('client_id', $clients->pluck('id'))->whereNotNull('owner_of_client_id')
                    ->whereNull('revoked_at')->pluck('user_id', 'client_id');
                $now = now();
                $rows = [];
                foreach ($clients as $client) {
                    $owner = $owners[$client->id] ?? null;
                    // News once a person: one who owns several accounts gets it once (code review 4 of #294). An
                    // announcement is about each account (its services): each one's.
                    if ($mail->kind === BulkMail::KIND_NEWS && $owner !== null && isset($people[$owner])) {
                        continue;
                    }
                    $people[$owner] = true;
                    $rows[] = ['bulk_mail_id' => $mail->id, 'client_id' => $client->id, 'user_id' => $owner,
                        'status' => BulkMailRecipient::STATUS_PENDING, 'created_at' => $now, 'updated_at' => $now];
                }
                BulkMailRecipient::query()->insertOrIgnore($rows);
            });
            $count = $mail->recipients()->count();
            $mail->forceFill(['status' => BulkMail::STATUS_SENDING, 'recipients_count' => $count, 'started_at' => now(), 'staff_id' => $staff?->id])->save();

            return $count;
        });
        SendBulkMailJob::dispatch($mail->id, 0)->afterCommit();

        return $count;
    }

    /** One batch (its per-minute share); the mail is done when none is left. @return int how many are left */
    public function sendBatch(BulkMail $mail, int $size): int
    {
        // Mail turned off for a while (settings, its template): they wait, never «skipped» for good (ADV A5 of #294).
        $template = NotificationTemplate::query()->where('key', $mail->templateKey())->first();
        if (config('settings.mail_disable') || $template === null || !$template->enabled) {
            return $mail->recipients()->where('status', BulkMailRecipient::STATUS_PENDING)->count();
        }
        $rows = $mail->recipients()->where('status', BulkMailRecipient::STATUS_PENDING)->orderBy('id')->limit(max(1, $size))->get();
        foreach ($rows as $row) {
            // Claimed first: a second worker on the same batch, or a retry, never mails it twice.
            if (BulkMailRecipient::query()->whereKey($row->id)->where('status', BulkMailRecipient::STATUS_PENDING)
                ->update(['status' => BulkMailRecipient::STATUS_SENDING, 'updated_at' => now()]) !== 1) {
                continue;
            }
            try {
                [$status, $error] = $this->deliver($mail, $row);
            } catch (Throwable $e) {
                report($e);
                [$status, $error] = [BulkMailRecipient::STATUS_FAILED, Str::limit($e->getMessage(), 250)];
            }
            $row->forceFill(['status' => $status, 'error' => $error, 'sent_at' => $status === BulkMailRecipient::STATUS_SENT ? now() : null])->save();
        }
        $left = $mail->recipients()->where('status', BulkMailRecipient::STATUS_PENDING)->count();
        if ($left === 0 && $mail->status === BulkMail::STATUS_SENDING) {
            $mail->forceFill(['status' => BulkMail::STATUS_SENT, 'finished_at' => now()])->save();
        }

        return $left;
    }

    /**
     * To staff, before it is sent: as the first account it goes to would get it (or a sample, with none), to their own
     * address only, with no live «stop these emails» of that customer (reviews of #294).
     */
    public function sendTest(BulkMail $mail, Staff $staff): void
    {
        $client = $this->recipients($mail)->orderBy('id')->first();
        $owner = $client?->owner();
        $template = NotificationTemplate::query()->where('key', $mail->templateKey())->firstOrFail();
        $locale = NotificationHelper::localeFor($client);
        $data = $this->data($mail, $client, $owner, $locale);
        if ($data['unsubscribe_url'] !== null) {
            $data['unsubscribe_url'] = url('/');
        }
        $test = (new Mail($template->inLocale($locale), TemplateRenderer::context($data, $owner)))->locale($locale);
        $test->renderedSubject = '[TEST] ' . $test->renderedSubject;
        MailFacade::to($staff->email)->queue($test);
    }

    /** @return array{0: string, 1: string|null} its status and why */
    private function deliver(BulkMail $mail, BulkMailRecipient $row): array
    {
        $client = $row->client;
        $owner = $client?->owner();
        if ($client === null || $owner === null) {
            return [BulkMailRecipient::STATUS_SKIPPED, 'no owner to mail'];
        }
        $template = NotificationTemplate::query()->where('key', $mail->templateKey())->firstOrFail();
        $preference = $owner->notificationsPreferences()->where('notification_template_id', $template->id)->first();
        if (!$template->isEnabledForPreference($preference, 'mail')) {
            return [BulkMailRecipient::STATUS_SKIPPED, 'news turned off by the customer'];
        }
        $locale = NotificationHelper::localeFor($client);
        $data = $this->data($mail, $client, $owner, $locale);
        // Its layout too, here: a template that does not parse is a failure, not a mail «sent» that never went (code review 7).
        $layout = $template->inLocale($locale);
        TemplateRenderer::render($layout->subject, TemplateRenderer::context($data, $owner), escape: false);
        TemplateRenderer::render($layout->body, TemplateRenderer::context($data, $owner));
        NotificationHelper::announcementNotification($client, $template->key, $data);

        return [BulkMailRecipient::STATUS_SENT, null];
    }

    /** @return array<string, mixed> its template's data for one account: its subject and message, in its language */
    private function data(BulkMail $mail, ?Client $client, ?User $owner, string $locale): array
    {
        $greek = $locale === 'el' && filled($mail->subject_el) && filled($mail->body_el);
        $data = [
            'client' => $client,
            'user' => $owner,
            'services' => $client === null ? collect() : self::services($client->services()->getQuery(), $mail->filters ?? [], all: ($mail->filters['target'] ?? 'all') !== 'services')->get(),
            'domains' => $client === null ? collect() : self::domains($client->domains()->getQuery(), $mail->filters ?? [], all: ($mail->filters['target'] ?? 'all') !== 'domains')->get(),
        ];
        $context = TemplateRenderer::context($data, $owner);
        $data['subject'] = TemplateRenderer::render($greek ? $mail->subject_el : $mail->subject, $context, escape: false);
        $data['content'] = new Html(TemplateRenderer::renderMarkdown((string) ($greek ? $mail->body_el : $mail->body), $context));
        $data['unsubscribe_url'] = $mail->kind === BulkMail::KIND_NEWS && $owner !== null
            ? URL::signedRoute('announcements.unsubscribe', ['user' => $owner->id]) : null;

        return $data;
    }

    /**
     * @param  Builder<Service>  $services
     * @param  array<string, mixed>  $filters
     * @return Builder<Service>
     */
    private static function services(Builder $services, array $filters, bool $all = false): Builder
    {
        if ($all) {
            return $services->where('status', Service::STATUS_ACTIVE); // its active services, for the message
        }
        $services->whereIn('status', array_filter((array) ($filters['service_statuses'] ?? [])) ?: [Service::STATUS_ACTIVE]);
        if (($products = array_filter((array) ($filters['products'] ?? []))) !== []) {
            $services->whereIn('product_id', $products);
        }
        if (($servers = array_filter((array) ($filters['servers'] ?? []))) !== []) {
            // Its own server, or its product's (Service::provisioningServer).
            $services->onServers($servers);
        }

        return $services;
    }

    /**
     * @param  Builder<Domain>  $domains
     * @param  array<string, mixed>  $filters
     * @return Builder<Domain>
     */
    private static function domains(Builder $domains, array $filters, bool $all = false): Builder
    {
        if ($all) {
            return $domains->where('status', 'active');
        }
        $domains->whereIn('status', array_filter((array) ($filters['domain_statuses'] ?? [])) ?: ['active']);
        if (($tlds = array_filter(array_map(fn ($tld) => ltrim(mb_strtolower(trim((string) $tld)), '.'), (array) ($filters['tlds'] ?? [])))) !== []) {
            $domains->whereIn('tld', $tlds);
        }

        return $domains;
    }
}
