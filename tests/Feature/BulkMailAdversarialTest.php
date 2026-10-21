<?php

namespace Tests\Feature;

use App\Admin\Resources\BulkMailResource\Pages\EditBulkMail;
use App\Jobs\SendBulkMailJob;
use App\Mail\Mail;
use App\Models\BulkMail;
use App\Models\BulkMailRecipient;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Mail\BulkMailer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Adversarial review of #294 (the mass mail), written without the author's context. Tests named *_guard pass and stay
 * as guards; the others fail on the PR as it stands and say what is wrong.
 */
class BulkMailAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.mail_disable' => false]);
    }

    private function account(string $first, array $user = []): Client
    {
        return User::factory()->create(['first_name' => $first, 'email' => mb_strtolower($first) . '@example.test'] + $user)->client();
    }

    private function service(Client $client, array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + ['client_id' => $client->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => Service::STATUS_ACTIVE]);
    }

    private function domain(Client $client, string $fqdn): Domain
    {
        [$sld, $tld] = explode('.', $fqdn, 2);
        $domainTld = DomainTld::query()->firstOrCreate(['tld' => $tld], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);

        return Domain::factory()->create(['client_id' => $client->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn, 'domain_tld_id' => $domainTld->id, 'status' => 'active']);
    }

    private function mailTo(array $attributes): BulkMail
    {
        return BulkMail::query()->create($attributes + ['name' => 'Notice', 'kind' => BulkMail::KIND_IMPORTANT, 'subject' => 'Notice',
            'body' => 'Dear {{ user.first_name }}', 'filters' => ['target' => 'all']]);
    }

    /** @return Collection<int, Mail> */
    private function mails(): Collection
    {
        return MailFacade::queued(Mail::class)->filter(fn (Mail $mail) => in_array($mail->templateKey, ['announcement', 'announcement_news'], true))->values();
    }

    /** @return list<string> */
    private function sentTo(): array
    {
        return $this->mails()->flatMap(fn (Mail $mail) => array_column($mail->to, 'address'))->sort()->values()->all();
    }

    private function sendAll(BulkMail $mail): void
    {
        app(BulkMailer::class)->start($mail, null);
        while (app(BulkMailer::class)->sendBatch($mail->refresh(), 100) > 0);
    }

    /**
     * A1 (medium). A customer's own name is escaped as HTML, then the message goes through Markdown: `[text](url)` in a
     * name (or a company, a service label) becomes a live link in a mail from us, to every recipient of a template that
     * prints it. A phishing link in our own name, signed by our mail server.
     */
    public function test_a_customers_name_cannot_put_a_link_into_the_message(): void
    {
        MailFacade::fake();
        $this->account('Anna', ['last_name' => '[Pay your invoice here](https://evil.example/pay) ![x](https://evil.example/pixel.gif)']);
        $mail = $this->mailTo(['body' => 'Dear {{ user.first_name }} {{ user.last_name }}, about {{ client.name }}.']);

        $this->sendAll($mail);

        $html = $this->mails()->sole()->renderedHtml;
        $this->assertStringNotContainsString('href="https://evil.example', $html, 'a customer value is text, never a link');
        $this->assertStringNotContainsString('src="https://evil.example', $html, 'a customer value is text, never an image');
    }

    /**
     * A2 (medium). The cron resumes a mail «left still for 10 minutes» by dispatching a new job, but the old chain's
     * delayed job is not lost when a worker was only down (database/redis queues keep it): both run, each dispatches its
     * own next batch, and from then on the mail goes at twice the pace (three times after a second outage…). Nobody is
     * mailed twice (the claim holds), but settings.bulk_mail_per_minute is no longer respected. A unique job (or a
     * per-mail lock / «next batch due at» on the mail) keeps one chain.
     */
    public function test_a_cron_resume_while_its_next_batch_is_still_queued_does_not_double_the_pace(): void
    {
        MailFacade::fake();
        Queue::fake();
        config(['settings.bulk_mail_per_minute' => 1]);
        foreach (['Anna', 'Babis', 'Chris', 'Dora'] as $name) {
            $this->account($name);
        }
        $mail = $this->mailTo([]);
        app(BulkMailer::class)->start($mail, null);
        Queue::assertPushed(SendBulkMailJob::class, 1); // the chain's job, still in the queue (a worker down for a while)

        $this->travel(11)->minutes();
        $this->artisan('app:cron-job')->assertExitCode(0);
        $queued = Queue::pushed(SendBulkMailJob::class)->filter(fn (SendBulkMailJob $job) => $job->bulkMailId === $mail->id);

        // The worker is back: what is in the queue runs.
        $queued->each(fn (SendBulkMailJob $job) => $job->handle(app(BulkMailer::class)));

        $this->assertLessThanOrEqual(1, count($this->sentTo()), 'one a minute, as set: not one per chain');
        $next = Queue::pushed(SendBulkMailJob::class)->filter(fn (SendBulkMailJob $job) => $job->bulkMailId === $mail->id)->count() - $queued->count();
        $this->assertLessThanOrEqual(1, $next, 'one next batch, not one per chain');
    }

    /**
     * A3 (low–medium). News is bulk marketing mail: Gmail and Yahoo (2024 bulk sender rules) want a one-click
     * List-Unsubscribe header (RFC 8058, List-Unsubscribe-Post), not only a link in the body. Without it, news from the
     * company's domain is more likely to land in spam, important announcements too (same domain reputation). The POST
     * route would then also need to work without a session's CSRF token (the mailbox provider posts it).
     */
    public function test_news_carries_a_one_click_list_unsubscribe_header(): void
    {
        // Read on the mail itself (the headers it adds when it is sent).
        MailFacade::fake();
        $this->account('Anna');
        $mail = $this->mailTo(['kind' => BulkMail::KIND_NEWS]);

        $this->sendAll($mail);

        $sent = $this->mails()->filter(fn (Mail $m) => in_array('anna@example.test', array_column($m->to, 'address'), true));
        $this->assertNotEmpty($sent, 'the news reached Anna');
        $headers = $sent->last()->unsubscribeHeaders();
        $this->assertArrayHasKey('List-Unsubscribe', $headers, 'news has a List-Unsubscribe header');
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post'] ?? null, 'and a one-click one (RFC 8058)');
    }

    /**
     * A4 (low). «Send a test» takes any address and is open to staff who may not send (update only): pointed at a
     * customer's address it is a one-off mass mail to them, without admin.bulk_mails.send. It is also rendered with the
     * first real recipient's data (name, services, domains), so a mistyped or outside address gets another customer's
     * details. A test should go to staff only (their own address, or a staff member's).
     */
    public function test_send_a_test_cannot_be_pointed_at_a_customer(): void
    {
        MailFacade::fake();
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $anna = $this->account('Anna');
        $this->service($anna);
        $this->account('Babis');
        $mail = $this->mailTo(['body' => 'Dear {{ user.first_name }}: {% for service in services %}[{{ service.id }}]{% endfor %}']);
        $writer = Staff::factory()->withPermissions(['admin.bulk_mails.viewAny', 'admin.bulk_mails.update'])->create(['email' => 'writer@example.test']);

        $this->livewireAs($writer)->test(EditBulkMail::class, ['record' => $mail->getRouteKey()])
            ->callAction('test', data: ['email' => 'babis@example.test']);

        $this->assertNotContains('babis@example.test', $this->sentTo(), 'a test goes to staff, not to a customer (nor Anna\'s details to Babis)');
    }

    /**
     * A5 (low). With mail turned off for a while (settings.mail_disable, the template disabled), every batch that runs
     * marks its accounts «skipped: mail turned off» for good: the mass mail is used up, nobody gets it, and it cannot be
     * sent again. It should wait (stay pending) until mail is back.
     */
    public function test_mail_turned_off_for_a_while_does_not_use_up_the_mass_mail(): void
    {
        MailFacade::fake();
        Queue::fake(); // the batches run below, one at a time
        $this->account('Anna');
        $mail = $this->mailTo([]);
        app(BulkMailer::class)->start($mail, null);

        config(['settings.mail_disable' => true]);
        app(BulkMailer::class)->sendBatch($mail->refresh(), 100);
        config(['settings.mail_disable' => false]);
        while (app(BulkMailer::class)->sendBatch($mail->refresh(), 100) > 0);
        (new SendBulkMailJob($mail->id))->handle(app(BulkMailer::class));

        $this->assertSame(['anna@example.test'], $this->sentTo(), 'sent once mail is back on');
    }

    /**
     * A6 (high). A mail being sent (or sent) can still be rewritten: its form is meant to be disabled and its Save
     * button is hidden, but EditRecord::save() is still callable (a Livewire request sets data.* and calls save, as
     * fillForm does here) with only admin.bulk_mails.update. The batches still to go read subject, body and filters
     * from the record, so the rest of the customers get a different text, with different services or domains listed,
     * than the one approved and sent; the record no longer shows what was sent. save() (and the test/send actions'
     * save) must refuse a mail that is not a draft (policy update: isDraft, or a guard in the page).
     */
    public function test_a_mail_being_sent_cannot_be_rewritten_by_a_direct_save(): void
    {
        MailFacade::fake();
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->account('Anna');
        $this->account('Babis');
        $mail = $this->mailTo([]);
        app(BulkMailer::class)->start($mail, null);
        config(['settings.bulk_mail_per_minute' => 1]);
        (new SendBulkMailJob($mail->id))->handle(app(BulkMailer::class)); // Anna has it; Babis is next
        $staff = Staff::factory()->withPermissions(['admin.bulk_mails.viewAny', 'admin.bulk_mails.update'])->create();

        try {
            $this->livewireAs($staff)->test(EditBulkMail::class, ['record' => $mail->getRouteKey()])
                ->fillForm(['subject' => 'Changed', 'body' => 'Changed text', 'filters.target' => 'domains'])->call('save');
        } catch (\Throwable) {
            // refused: fine
        }
        (new SendBulkMailJob($mail->id))->handle(app(BulkMailer::class));

        $fresh = $mail->fresh();
        $this->assertSame(['Notice', 'Dear {{ user.first_name }}', 'all'], [$fresh->subject, $fresh->body, $fresh->filters['target']], 'what was sent stays as it was');
        $this->assertSame(['Notice', 'Notice'], $this->mails()->map(fn (Mail $m) => $m->renderedSubject)->all(), 'every customer gets the same message');
    }

    // ---- guards: these pass on the PR and stay ----

    /** G1. Without admin.bulk_mails.send, calling the hidden Send action directly (Livewire) sends nothing. */
    public function test_staff_without_send_cannot_send_by_calling_the_action_guard(): void
    {
        MailFacade::fake();
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->account('Anna');
        $mail = $this->mailTo([]);
        $writer = Staff::factory()->withPermissions(['admin.bulk_mails.viewAny', 'admin.bulk_mails.update', 'admin.bulk_mails.create', 'admin.bulk_mails.delete'])->create();

        try {
            $this->livewireAs($writer)->test(EditBulkMail::class, ['record' => $mail->getRouteKey()])->callAction('send');
        } catch (\Throwable) {
            // refused: fine
        }

        $this->assertTrue($mail->fresh()->isDraft());
        $this->assertSame(0, BulkMailRecipient::query()->count());
        Queue::assertNotPushed(SendBulkMailJob::class);
    }

    /** G2. A sent mail cannot be deleted (its action hidden, the policy refuses), even by a direct call. */
    public function test_a_sent_mail_cannot_be_deleted_guard(): void
    {
        MailFacade::fake();
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->account('Anna');
        $mail = $this->mailTo([]);
        app(BulkMailer::class)->start($mail, null);
        $staff = Staff::factory()->withPermissions(['admin.bulk_mails.viewAny', 'admin.bulk_mails.update', 'admin.bulk_mails.delete', 'admin.bulk_mails.send'])->create();

        try {
            $this->livewireAs($staff)->test(EditBulkMail::class, ['record' => $mail->getRouteKey()])->callAction('delete');
        } catch (\Throwable) {
        }

        $this->assertNotNull($mail->fresh());
        $this->assertSame(1, $mail->recipients()->count());
    }

    /**
     * G3. The stop link is its login's only: another id with its signature, a missing signature, and a GET change
     * nothing. Note (informational, not a failure): it never expires (signedRoute without an expiry); usual for an
     * unsubscribe link, and it can only turn news off.
     */
    public function test_the_stop_link_cannot_unsubscribe_someone_else_guard(): void
    {
        $anna = $this->account('Anna')->owner();
        $babis = $this->account('Babis')->owner();
        $url = URL::signedRoute('announcements.unsubscribe', ['user' => $anna->id]);

        $this->post(str_replace("/unsubscribe/{$anna->id}?", "/unsubscribe/{$babis->id}?", $url))->assertForbidden();
        $this->post(route('announcements.unsubscribe', ['user' => $babis->id]))->assertForbidden();
        $this->get($url)->assertOk();
        $this->assertSame(0, $babis->notificationsPreferences()->count() + $anna->notificationsPreferences()->count());

        $this->travel(2)->years();
        $this->get($url)->assertOk(); // no expiry (see the note)
    }

    /** G4. A revoked owner gets nothing; a sub-user of the account never gets the account's mass mail. */
    public function test_a_revoked_owner_and_a_sub_user_get_nothing_guard(): void
    {
        MailFacade::fake();
        $revoked = $this->account('Anna');
        ClientUser::query()->where('client_id', $revoked->id)->whereNotNull('owner_of_client_id')->update(['revoked_at' => now()]);
        $shared = $this->account('Babis');
        $sub = User::factory()->withoutClient()->create(['first_name' => 'Sub', 'email' => 'sub@example.test']); // a login on Babis's account only
        ClientUser::query()->create(['client_id' => $shared->id, 'user_id' => $sub->id, 'granted_at' => now()]);
        MailFacade::fake(); // forget the logins' own mails

        $this->sendAll($this->mailTo([]));

        $this->assertNotContains('anna@example.test', $this->sentTo());
        $this->assertNotContains('sub@example.test', $this->sentTo());
        $this->assertContains('babis@example.test', $this->sentTo());
    }

    /** G5. A template reaches no secret (passwords, 2FA, EPP code, staff notes) and no other account's records. */
    public function test_a_template_reaches_no_secret_and_no_other_account_guard(): void
    {
        MailFacade::fake();
        $anna = $this->account('Anna');
        $this->service($anna, ['notes' => 'STAFFNOTE']);
        $this->domain($anna, 'anna.gr')->forceFill(['epp_code' => 'EPPSECRET'])->save();
        $babis = $this->account('Babis');
        $other = $this->service($babis);
        $mail = $this->mailTo(['filters' => ['target' => 'all'], 'body' => 'P={{ user.password }} T={{ user.tfa_secret }} '
            . '{% for service in services %}S={{ service.id }} SP={{ service.password }} N={{ service.notes }} '
            . '{% for u in service.client.users %}U={{ u.email }}{% endfor %}{% endfor %}'
            . '{% for domain in domains %}E={{ domain.epp_code }}{% endfor %} ALL={{ services }}']);

        $this->sendAll($mail);

        $html = html_entity_decode($this->mails()->first(fn (Mail $m) => $m->to[0]['address'] === 'anna@example.test')->renderedHtml);
        foreach (['$2y$', 'STAFFNOTE', 'EPPSECRET', 'babis@example.test', "S={$other->id} "] as $leak) {
            $this->assertStringNotContainsString($leak, $html);
        }
        $this->assertMatchesRegularExpression('/P=\s*T=/', $html);
    }

    /** G6. Two jobs on the same mail at once (a retry, the cron) never mail anyone twice. */
    public function test_two_jobs_never_mail_anyone_twice_guard(): void
    {
        MailFacade::fake();
        Queue::fake();
        foreach (['Anna', 'Babis', 'Chris'] as $name) {
            $this->account($name);
        }
        $mail = $this->mailTo([]);
        app(BulkMailer::class)->start($mail, null);
        for ($i = 0; $i < 4; $i++) {
            (new SendBulkMailJob($mail->id))->handle(app(BulkMailer::class));
        }

        $this->assertSame(['anna@example.test', 'babis@example.test', 'chris@example.test'], $this->sentTo());
        $this->assertSame(BulkMail::STATUS_SENT, $mail->fresh()->status);
    }
}
