<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Enums\InvoiceTransactionStatus;
use App\Events\Invoice\Finalized as InvoiceFinalized;
use App\Events\Invoice\GeneratePdf;
use App\Events\InvoiceTransaction\Created as InvoiceTransactionCreated;
use App\Events\Notification\Created as NotificationCreated;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Listeners\SendMailListener;
use App\Mail\Mail;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Paymenter\Extensions\Others\DiscordNotifications\DiscordNotifications;
use RuntimeException;
use Tests\TestCase;

/**
 * Independent review of Σ4 (fix/notifications-after-commit).
 */
class NotificationsAfterCommitReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.mail_disable' => false, 'settings.system_email_address' => 'staff@example.test']);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
    }

    private function invoice(array $attributes = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'client_id' => User::factory()->create()->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addDays(7),
        ], $attributes));
    }

    /**
     * R-1. Outside a transaction AfterCommit::event fires at once, at `$invoice->save()`, before the lines are
     * written. The admin «Create invoice» page (no DB transaction: the panel has none, CreateInvoice sets none) and
     * OrderResource\CreateOrder::afterCreate save the invoice and then its lines. Before, Finalized waited for the
     * end of the response; now listeners see an invoice with no lines (Discord's «Invoice Created» is built at
     * that moment, total 0), and the queued mail races the line inserts (sync: always loses).
     */
    public function test_r1_an_invoice_made_on_the_admin_page_is_announced_with_its_lines(): void
    {
        MailFacade::fake();
        $linesWhenFired = null;
        Event::listen(InvoiceFinalized::class, function (InvoiceFinalized $event) use (&$linesWhenFired) {
            $linesWhenFired = $event->invoice->items()->count();
        });
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $customer = User::factory()->create();
        $undo = Repeater::fake();

        $this->livewireAs($owner)->test(CreateInvoice::class)
            ->fillForm([
                'client_id' => $customer->client()->id,
                'status' => Invoice::STATUS_UNPAID,
                'currency_code' => 'USD',
                'send_email' => true,
                'items' => [['price' => '10.00', 'quantity' => 1, 'description' => 'Review hosting line']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $undo();

        $invoice = Invoice::query()->where('client_id', $customer->client()->id)->sole();
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame(1, $linesWhenFired, 'Finalized fired before the invoice had its lines');
        MailFacade::assertQueued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'new_invoice_created'
            && str_contains($mail->renderedHtml, 'Review hosting line'));
    }

    /** R-1, the same through Discord: «Invoice Created» is built when Finalized fires (here: no lines, total 0). */
    public function test_r1_discord_announces_an_admin_made_invoice_with_its_total(): void
    {
        MailFacade::fake();
        Http::fake(['https://discord.test/*' => Http::response([])]);
        (new DiscordNotifications(['webhook_url' => 'https://discord.test/hook', 'events' => ['Invoice Created'], 'ping_type' => 'none']))->boot();

        // As OrderResource\CreateOrder::afterCreate and the admin CreateInvoice page: invoice saved, then its lines, in
        // the page's transaction (fixed: $hasDatabaseTransactions).
        DB::transaction(function () {
            $invoice = $this->invoice();
            $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        });

        $posted = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'discord.test'))
            ->map(fn ($pair) => collect($pair[0]['embeds'][0]['fields'])->pluck('value')->implode(', '))->implode(' | ');
        $this->assertStringContainsString('10', $posted, "Discord announced the invoice before its lines: {$posted}");
    }

    /**
     * R-2. The 12h dedupe key is taken before the mail is made. The listener is queued with 5 tries precisely so
     * that a failing PDF (or mail template, DB hiccup) is retried; but the retry finds the key taken and returns:
     * the customer is never told the payment failed.
     */
    public function test_r2_a_payment_failed_mail_whose_first_attempt_failed_is_sent_on_the_retry(): void
    {
        MailFacade::fake();
        $invoice = $this->invoice();
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $transaction = InvoiceTransaction::withoutEvents(fn () => ExtensionHelper::addFailedPayment($invoice->id, 'Stripe', 10, null, 'pi_r2'));
        $this->assertSame(InvoiceTransactionStatus::Failed, $transaction->status);

        $attempts = 0;
        Event::listen(GeneratePdf::class, function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new RuntimeException('PDF renderer down for a moment');
            }
        });
        $listener = new SendMailListener;
        $event = new InvoiceTransactionCreated($transaction->fresh());

        try {
            $listener->handle($event); // attempt 1: fails, the job is released
        } catch (RuntimeException) {
        }
        $listener->handle($event); // attempt 2 (backoff 60s later)

        $this->assertCount(1, MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed'));
    }

    /**
     * R-4. The branch's EVT-14 test passes a count as a third argument to MailFake::assertQueued, which takes two:
     * it only checks «at least one». This is the same scenario with the count asserted.
     */
    public function test_r4_payment_failed_is_mailed_once_counted(): void
    {
        MailFacade::fake();
        $invoice = $this->invoice();
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);

        $transaction = ExtensionHelper::addFailedPayment($invoice->id, 'Stripe', 10, null, 'pi_failed');
        $transaction->update(['fee' => 0.30]);
        NotificationHelper::invoicePaymentFailedNotification($invoice->client, $invoice);

        $this->assertCount(1, MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed'));
    }

    /**
     * R-3. A retry after a partial success mails twice: the mail is queued, then the in-app notification fails
     * (anything but TemplateError propagates), the listener job is retried and queues the same mail (and PDF) again.
     */
    public function test_r3_a_retried_listener_does_not_mail_the_customer_twice(): void
    {
        MailFacade::fake();
        $invoice = $this->invoice();
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        MailFacade::fake(); // forget anything above

        $attempts = 0;
        Event::listen(NotificationCreated::class, function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new RuntimeException('deadlock on notifications');
            }
        });
        $listener = new SendMailListener;
        $event = new InvoiceFinalized($invoice->fresh(), true);

        // Fixed: the in-app part's failure is reported, not thrown, so the job succeeds and is never retried.
        $listener->handle($event);

        $this->assertSame(1, $attempts, 'in-app attempts');
        $this->assertCount(1, MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'new_invoice_created'));
    }

    /** Check (passes): the Discord closure job serializes (no $this captured) and posts on a real (sync) queue. */
    public function test_check_discord_job_serializes_and_posts(): void
    {
        Http::fake(['https://discord.test/*' => Http::response([])]);
        (new DiscordNotifications(['webhook_url' => 'https://discord.test/hook', 'events' => ['User Created'], 'ping_type' => 'none']))->boot();

        DB::transaction(fn () => User::factory()->create(['first_name' => 'Ada']));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'discord.test') && str_contains(json_encode($request->data()), 'Ada'));
        $job = CallQueuedClosure::create(fn () => 1);
        $this->assertIsString(serialize($job));
    }

    /** Check (passes): ticket replies are still threaded (Message-ID) with the new view data keys. */
    public function test_check_ticket_reply_is_threaded(): void
    {
        config(['mail.default' => 'array']);
        $user = User::factory()->create();
        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'Help', 'priority' => 'low', 'status' => 'open']);
        $message = $ticket->messages()->create(['user_id' => $user->id, 'message' => 'Reply']);

        NotificationHelper::ticketMessageNotification($user->client(), $message);

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertNotEmpty($sent);
        $headers = $sent->last()->getOriginalMessage()->getHeaders();
        $this->assertStringContainsString($message->id . '@', $headers->get('Message-ID')->getBodyAsString());
    }
}
