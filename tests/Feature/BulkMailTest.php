<?php

namespace Tests\Feature;

use App\Admin\Resources\BulkMailResource;
use App\Admin\Resources\BulkMailResource\Pages\CreateBulkMail;
use App\Admin\Resources\BulkMailResource\Pages\EditBulkMail;
use App\Exceptions\DisplayException;
use App\Jobs\SendBulkMailJob;
use App\Mail\Mail;
use App\Models\BulkMail;
use App\Models\BulkMailRecipient;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\EmailLog;
use App\Models\NotificationTemplate;
use App\Models\Server;
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
 * The mass mail (ROADMAP 6δ, owner 2026-10-21: «με φίλτρα … να κλείσουμε τη ψαλίδα με mail notifications»): the
 * accounts its filters find, each once, a batch a minute; news only to those who did not turn it off.
 */
class BulkMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MailFacade::fake();
        config(['settings.mail_disable' => false]);
    }

    private function account(string $first, array $user = []): Client
    {
        return User::factory()->create(['first_name' => $first, 'email' => mb_strtolower($first) . '@example.test'] + $user)->client();
    }

    private function service(Client $client, array $attributes = []): Service
    {
        $product = $this->createProduct($attributes['product'] ?? []);
        unset($attributes['product']);

        return Service::factory()->create($attributes + ['client_id' => $client->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => Service::STATUS_ACTIVE]);
    }

    private function domain(Client $client, string $fqdn, string $status = 'active'): Domain
    {
        [$sld, $tld] = explode('.', $fqdn, 2);
        $domainTld = DomainTld::query()->firstOrCreate(['tld' => $tld], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);

        return Domain::factory()->create(['client_id' => $client->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn, 'domain_tld_id' => $domainTld->id, 'status' => $status]);
    }

    private function mailTo(array $attributes): BulkMail
    {
        return BulkMail::query()->create($attributes + ['name' => 'Notice', 'kind' => BulkMail::KIND_IMPORTANT, 'subject' => 'Notice for {{ client.name }}',
            'body' => 'Dear {{ user.first_name }}, your services: {% for service in services %}[{{ service.id }}]{% endfor %} domains: {% for domain in domains %}[{{ domain.fqdn }}]{% endfor %}']);
    }

    /** @return list<string> the addresses it reached */
    private function sentTo(): array
    {
        return $this->mails()->flatMap(fn (Mail $mail) => array_column($mail->to, 'address'))->sort()->values()->all();
    }

    /** @return Collection<int, Mail> the mass mail's, not the accounts' own (a new login's) */
    private function mails(): Collection
    {
        return MailFacade::queued(Mail::class)->filter(fn (Mail $mail) => in_array($mail->templateKey, ['announcement', 'announcement_news'], true))->values();
    }

    private function sendAll(BulkMail $mail): void
    {
        app(BulkMailer::class)->start($mail, null);
        while (app(BulkMailer::class)->sendBatch($mail->refresh(), 100) > 0);
    }

    public function test_its_filters_find_the_accounts_with_matching_services_or_domains(): void
    {
        $server = Server::query()->create(['name' => 'rigel', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $onRigel = $this->account('Anna');
        $this->service($onRigel, ['server_id' => $server->id]);
        $viaProduct = $this->account('Babis');
        $this->service($viaProduct, ['product' => ['server_id' => $server->id]]); // its product's server
        $suspended = $this->account('Chris');
        $this->service($suspended, ['server_id' => $server->id, 'status' => Service::STATUS_SUSPENDED]);
        $elsewhere = $this->account('Dora');
        $this->service($elsewhere);
        $gr = $this->account('Eleni');
        $this->domain($gr, 'eleni.gr');
        $this->domain($this->account('Fotis'), 'fotis.com');
        $this->account('Giorgos'); // nothing at all
        $group = ClientGroup::query()->create(['name' => 'Resellers', 'discount_percent' => 0]);
        $elsewhere->forceFill(['client_group_id' => $group->id])->save();

        $ids = fn (BulkMail $mail) => app(BulkMailer::class)->recipients($mail)->orderBy('id')->pluck('id')->all();

        $this->assertSame([$onRigel->id, $viaProduct->id], $ids($this->mailTo(['filters' => ['target' => 'services', 'servers' => [$server->id]]])));
        $this->assertSame([$onRigel->id, $viaProduct->id, $suspended->id], $ids($this->mailTo(['filters' => ['target' => 'services', 'servers' => [$server->id], 'service_statuses' => ['active', 'suspended']]])));
        $this->assertSame([$gr->id], $ids($this->mailTo(['filters' => ['target' => 'domains', 'tlds' => ['.GR']]])));
        $this->assertCount(7, $ids($this->mailTo(['filters' => ['target' => 'all']])));
        $this->assertNotContains(Client::query()->whereHas('users', fn ($u) => $u->where('first_name', 'Giorgos'))->value('id'),
            $ids($this->mailTo(['filters' => ['target' => 'all', 'active_only' => true]])), 'only customers with something active');
        $this->assertSame([$elsewhere->id], $ids($this->mailTo(['filters' => ['target' => 'all', 'client_groups' => [$group->id]]])));
    }

    public function test_each_account_is_mailed_once_with_its_own_services_a_batch_a_minute(): void
    {
        Queue::fake();
        $anna = $this->account('Anna', ['last_name' => '<b>Bold</b>']);
        $service = $this->service($anna);
        $this->domain($anna, 'anna.gr');
        $this->account('Babis');
        $mail = $this->mailTo(['filters' => ['target' => 'all']]);

        $this->assertSame(2, app(BulkMailer::class)->start($mail, null));
        Queue::assertPushed(SendBulkMailJob::class, 1);
        try {
            app(BulkMailer::class)->start($mail->refresh(), null);
            $this->fail('sent twice');
        } catch (DisplayException) {
        }

        config(['settings.bulk_mail_per_minute' => 1]);
        (new SendBulkMailJob($mail->id))->handle(app(BulkMailer::class));
        $this->assertSame(['anna@example.test'], $this->sentTo(), 'one a minute: the next one is the next job');
        Queue::assertPushed(SendBulkMailJob::class, 2);
        (new SendBulkMailJob($mail->id))->handle(app(BulkMailer::class));
        (new SendBulkMailJob($mail->id))->handle(app(BulkMailer::class)); // nothing left: no one twice

        $this->assertSame(['anna@example.test', 'babis@example.test'], $this->sentTo());
        $this->assertSame(BulkMail::STATUS_SENT, $mail->fresh()->status);
        $anna = $this->mails()->first(fn (Mail $m) => $m->to[0]['address'] === 'anna@example.test');
        $this->assertStringContainsString("Dear Anna, your services: [{$service->id}] domains: [anna.gr]", $anna->renderedHtml);
        $this->assertStringContainsString('Notice for Anna &lt;b&gt;Bold&lt;/b&gt;', e($anna->renderedSubject), 'a value is text');
        $this->assertStringNotContainsString('<b>Bold</b>', $anna->renderedHtml);
        $this->assertStringNotContainsString('unsubscribe', $anna->renderedHtml, 'an important announcement has no «stop these»');
        $this->assertSame(2, EmailLog::query()->where('template_key', 'announcement')->count(), 'on the email log, on each account');
    }

    public function test_news_is_not_sent_to_those_who_turned_it_off_and_tells_how_to(): void
    {
        $anna = $this->account('Anna');
        $babis = $this->account('Babis');
        $news = NotificationTemplate::query()->where('key', 'announcement_news')->sole();
        $babis->owner()->notificationsPreferences()->create(['notification_template_id' => $news->id, 'mail_enabled' => false, 'in_app_enabled' => false]);
        $mail = $this->mailTo(['kind' => BulkMail::KIND_NEWS, 'filters' => ['target' => 'all']]);

        $this->sendAll($mail);

        $this->assertSame(['anna@example.test'], $this->sentTo());
        $this->assertSame([BulkMailRecipient::STATUS_SENT, BulkMailRecipient::STATUS_SKIPPED],
            [$mail->recipients()->where('client_id', $anna->id)->value('status'), $mail->recipients()->where('client_id', $babis->id)->value('status')]);
        $this->assertStringContainsString(URL::signedRoute('announcements.unsubscribe', ['user' => $anna->owner()->id]),
            html_entity_decode($this->mails()->first()->renderedHtml));
    }

    public function test_the_stop_link_asks_first_and_turns_news_off_for_that_login_only(): void
    {
        $user = $this->account('Anna')->owner();
        $url = URL::signedRoute('announcements.unsubscribe', ['user' => $user->id]);
        $news = NotificationTemplate::query()->where('key', 'announcement_news')->sole();

        $this->get($url)->assertOk()->assertSee(__('account.news_stop'));
        $this->assertSame(0, $user->notificationsPreferences()->count(), 'opened by a mail scanner: nothing changes');
        $this->get($url . 'x')->assertForbidden();

        $this->post($url)->assertOk()->assertSee(__('account.news_stopped'));
        $this->assertFalse((bool) $user->notificationsPreferences()->where('notification_template_id', $news->id)->value('mail_enabled'));
    }

    public function test_an_account_reading_greek_gets_the_greek_text_when_there_is_one(): void
    {
        config(['settings.app_language' => 'el']);
        $this->account('Anna');
        $mail = $this->mailTo(['filters' => ['target' => 'all'], 'subject_el' => 'Ενημέρωση για {{ client.name }}', 'body_el' => 'Αγαπητή {{ user.first_name }}']);

        $this->sendAll($mail);

        $sent = $this->mails()->sole();
        $this->assertSame('Ενημέρωση για Anna ' . User::query()->where('first_name', 'Anna')->value('last_name'), $sent->renderedSubject);
        $this->assertStringContainsString('Αγαπητή Anna', $sent->renderedHtml);
    }

    public function test_staff_write_test_and_send_it_with_the_permission_to_send(): void
    {
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->account('Anna');
        $writer = Staff::factory()->withPermissions(['admin.bulk_mails.viewAny', 'admin.bulk_mails.create', 'admin.bulk_mails.update'])->create(['email' => 'writer@example.test']);

        $this->livewireAs($writer)->test(CreateBulkMail::class)->fillForm([
            'name' => 'Rigel moves', 'kind' => BulkMail::KIND_IMPORTANT, 'subject' => 'Rigel moves on Sunday', 'body' => 'Dear {{ user.first_name }}', 'filters.target' => 'all',
        ])->call('create')->assertHasNoFormErrors();
        $mail = BulkMail::query()->sole();
        $this->assertTrue($mail->isDraft());

        $page = $this->livewireAs($writer)->test(EditBulkMail::class, ['record' => $mail->getRouteKey()]);
        $page->assertActionHidden('send');
        $page->callAction('test', data: ['email' => 'writer@example.test']);
        $this->assertSame(['writer@example.test'], $this->sentTo());
        $this->assertStringStartsWith('[TEST] ', $this->mails()->sole()->renderedSubject);
        $this->assertSame(0, BulkMailRecipient::query()->count(), 'a test sends nothing to customers');

        $sender = Staff::factory()->withPermissions(['admin.bulk_mails.viewAny', 'admin.bulk_mails.update', 'admin.bulk_mails.send'])->create();
        $this->livewireAs($sender)->test(EditBulkMail::class, ['record' => $mail->getRouteKey()])->callAction('send');
        $this->assertSame([BulkMail::STATUS_SENDING, 1], [$mail->fresh()->status, $mail->fresh()->recipients_count]);
        Queue::assertPushed(SendBulkMailJob::class);

        $this->livewireAs($sender)->test(EditBulkMail::class, ['record' => $mail->getRouteKey()])->assertActionHidden('send')->assertActionHidden('delete');
    }

    public function test_staff_without_the_permission_do_not_see_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny'])->create();

        $this->actingAs($staff)->get(BulkMailResource::getUrl('index'))->assertForbidden();
    }
}
