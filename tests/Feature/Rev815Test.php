<?php

namespace Tests\Feature;

use App\Admin\Widgets\Overview;
use App\Livewire\Admin\TicketNotifier;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Independent review of #815 (live tickets badge, «New ticket» notice, linked dashboard cards).
 */
class Rev815Test extends TestCase
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

    /**
     * REV-815-1: a ticket's subject is the sender's words (any mail to the support address opens one). The notifier hands
     * it to `new FilamentNotification().body(d.subject)`; `dataset` decodes the attribute, and Filament renders a
     * notification's body as HTML (`str($body)->sanitizeHtml()`, which keeps links, images and `style`). So a subject
     * of markup becomes markup in every staff member's admin: a link styled over the page, an image from anywhere.
     * Here: the attribute as the browser decodes it, sent as the JS does, rendered as Filament's Notifications does.
     */
    public function test_a_ticket_subject_is_shown_as_text_in_the_new_ticket_notice_never_as_html(): void
    {
        $this->staff();
        $subject = '<a href="https://evil.example/login" style="position:fixed;inset:0">Re-enter your password</a>';
        Ticket::query()->create(['subject' => $subject, 'status' => Ticket::STATUS_OPEN, 'priority' => 'low', 'department_id' => $this->support->id,
            'guest_name' => 'M', 'guest_email' => 'm@example.com']);

        $html = Livewire::withoutLazyLoading()->test(TicketNotifier::class)->html();
        $this->assertSame(1, preg_match('/data-subject="([^"]*)"/', $html, $m));
        $dataset = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5); // what `this.$el.dataset.subject` is

        // As FilamentNotification.send() → Notifications::pushNotificationFromEvent → the rendered notice.
        $notice = Notification::fromArray(['id' => 'n1', 'title' => 'New ticket', 'body' => $dataset, 'status' => 'info'])->toEmbeddedHtml();

        $this->assertStringNotContainsString('href="https://evil.example', $notice, 'the subject is the sender\'s text, never a link in the admin');
    }

    /**
     * REV-815-2: the Tickets card now links to the tickets' list, but unlike the dashboard's other linked cards
     * (Pending Orders, Clients with credit, Pending Cancellations, Module actions) it is not gated by the list's
     * permission: staff who may see the dashboard and not the tickets get a card that opens a 403.
     */
    public function test_the_tickets_card_does_not_link_staff_who_may_not_open_the_tickets(): void
    {
        $this->staff(['admin.widgets.overview', 'admin.services.viewAny']);

        $stats = collect((fn () => $this->getStats())->call(Livewire::test(Overview::class)->instance()))
            ->keyBy(fn ($stat) => $stat->getLabel());

        $url = isset($stats['Tickets']) ? $stats['Tickets']->getUrl() : null;
        if ($url !== null) {
            $this->get($url)->assertOk();
        }
        $this->assertTrue(true);
    }
}
