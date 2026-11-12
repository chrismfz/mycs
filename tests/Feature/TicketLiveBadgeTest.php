<?php

namespace Tests\Feature;

use App\Admin\AdminSidebar;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\TicketResource;
use App\Admin\Widgets\Overview;
use App\Livewire\Admin\TicketNotifier;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Owner 2026-11-12: the tickets' badge live on every admin page with a «New ticket» notice, the dashboard's Tickets and
 * Services cards open their lists, and the staff's mail names a message's attachments (names and sizes, not the files).
 */
class TicketLiveBadgeTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'EUR']);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function staff(array $permissions = ['*']): Staff
    {
        $staff = Staff::factory()->create(['email' => 'agent@myip.test', 'role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function ticket(string $subject, string $status = Ticket::STATUS_OPEN): Ticket
    {
        return Ticket::query()->create(['subject' => $subject, 'status' => $status, 'priority' => 'low', 'department_id' => $this->support->id,
            'guest_name' => 'M', 'guest_email' => 'm@example.com']);
    }

    /** @return Collection<int, Email> */
    private function sent(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage());
    }

    public function test_the_notifier_sends_the_waiting_count_and_the_newest_ticket(): void
    {
        $this->staff();
        $this->ticket('Older');
        $this->ticket('Answered', Ticket::STATUS_ANSWERED);
        $newest = $this->ticket('Site <down>');

        $html = Livewire::withoutLazyLoading()->test(TicketNotifier::class)->html();

        $this->assertStringContainsString('data-count="2"', $html);
        $this->assertStringContainsString('data-latest="' . $newest->id . '"', $html);
        $this->assertStringContainsString(e(e(__('New ticket') . ' #' . $newest->reference)), $html);
        $this->assertStringContainsString(e(e('Site <down>')), $html, 'the subject escaped for the notice, which draws HTML');
        $this->assertStringNotContainsString('Site <down>', $html);
        $this->assertStringContainsString(e(TicketResource::getUrl('edit', ['record' => $newest])), $html);
    }

    public function test_staff_who_may_not_see_tickets_get_no_count_and_no_notifier(): void
    {
        $this->staff(['admin.invoices.viewAny']);
        $this->ticket('Help');

        $html = Livewire::withoutLazyLoading()->test(TicketNotifier::class)->html();

        $this->assertStringContainsString('data-count="0"', $html);
        $this->assertStringContainsString('data-latest="0"', $html);
        $this->assertStringNotContainsString('Help', $html);
        $this->get('/' . Filament::getPanel('admin')->getPath())->assertOk()->assertDontSee('mycs-ticket-notifier', false);
    }

    public function test_the_sidebar_tickets_queue_listens_for_the_live_count(): void
    {
        $this->staff();
        $this->get('/' . Filament::getPanel('admin')->getPath())->assertOk()->assertSee('mycs-ticket-notifier', false)->assertSee('x-init="$wire.__lazyLoad', false);
        $this->ticket('Help');

        $queue = collect(AdminSidebar::queues())->firstWhere('label', 'Tickets awaiting reply');
        $this->assertTrue($queue['live']);
        $this->assertFalse(collect(AdminSidebar::queues())->firstWhere('label', 'Pending orders')['live']);

        $html = view('filament.admin.sidebar-work')->render();
        $this->assertStringContainsString('x-on:mycs-tickets-awaiting.window', $html);
    }

    public function test_the_tickets_and_services_cards_open_their_lists(): void
    {
        $this->staff();

        $stats = collect((fn () => $this->getStats())->call(Livewire::test(Overview::class)->instance()))
            ->keyBy(fn ($stat) => $stat->getLabel());

        $this->assertSame(TicketResource::getUrl('index'), $stats['Tickets']->getUrl());
        $this->assertSame(ServiceResource::getUrl('index'), $stats['Services']->getUrl());
    }

    public function test_the_staff_mail_names_the_attachments_stored_with_the_message(): void
    {
        $agent = $this->staff(['admin.tickets.viewAny']);
        $this->support->notifyStaff()->sync([$agent->id]);

        // As InboundRouter and the client area do: the message and its files in one transaction.
        DB::transaction(function () {
            $ticket = $this->ticket('Invoice question');
            $message = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'See attached', 'via' => TicketMessage::VIA_EMAIL]);
            TicketMessageAttachment::query()->create(['ticket_message_id' => $message->id, 'path' => 'tickets/uploads/a', 'filename' => 'scan<1>.pdf', 'filesize' => 2048, 'mime_type' => 'application/pdf']);
        });

        $mail = $this->sent()->filter(fn (Email $email) => $email->getTo()[0]->getAddress() === 'agent@myip.test')->sole();
        $html = (string) $mail->getHtmlBody();
        $this->assertStringContainsString(e('scan<1>.pdf'), $html);
        $this->assertStringNotContainsString('scan<1>.pdf', $html);
        $this->assertStringContainsString('2 KB', $html);
        $this->assertSame([], $mail->getAttachments(), 'names only, never the files');
    }

    public function test_a_message_without_files_has_no_attachments_line(): void
    {
        $agent = $this->staff(['admin.tickets.viewAny']);
        $this->support->notifyStaff()->sync([$agent->id]);
        $ticket = $this->ticket('Plain');

        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'No files', 'via' => TicketMessage::VIA_EMAIL]);

        $html = (string) $this->sent()->sole()->getHtmlBody();
        $this->assertStringContainsString('No files', $html);
        $this->assertStringNotContainsString(__('Attachments') . ':', $html);
    }
}
