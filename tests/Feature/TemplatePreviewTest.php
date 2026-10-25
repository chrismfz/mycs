<?php

namespace Tests\Feature;

use App\Admin\Resources\NotificationTemplateResource\Pages\EditNotificationTemplate;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Services\Mail\TemplatePreview;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Staff see (and get) an email template as a customer would, before it goes live (owner 2026-10-25). */
class TemplatePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EmailTemplateSeeder::class);
    }

    public function test_every_template_previews_in_greek_and_english_and_writes_nothing(): void
    {
        $counts = fn () => [Invoice::query()->count(), Service::query()->count(), Ticket::query()->count(), DB::table('email_logs')->count()];
        $before = $counts();
        foreach (NotificationTemplate::query()->get() as $template) {
            foreach (['el', 'en'] as $locale) {
                $mail = TemplatePreview::render($template, $locale);
                $this->assertNotSame('', $mail['subject'], "{$template->key} ({$locale})");
                $this->assertStringContainsString('<html', $mail['html'], "{$template->key} ({$locale})");
                $this->assertStringNotContainsString('{{', $mail['html'], "{$template->key} ({$locale})");
            }
        }
        $this->assertSame($before, $counts(), 'a preview writes nothing');
    }

    public function test_the_invoice_mail_shows_its_made_up_lines_and_total(): void
    {
        $mail = TemplatePreview::render(NotificationTemplate::query()->where('key', 'new_invoice_created')->sole(), 'el');

        $this->assertStringContainsString('32999', $mail['subject'] . $mail['html']);
        $this->assertStringContainsString('Web Hosting Pro', $mail['html']);
    }

    public function test_a_write_while_rendering_is_refused(): void
    {
        $template = NotificationTemplate::query()->where('key', 'invoice_paid')->sole();
        $template->body = '{{ invoice.id }}';
        TemplatePreview::render($template, 'en'); // installs the guard

        // While one renders, a write is refused.
        $guarding = new \ReflectionProperty(TemplatePreview::class, 'guarding');
        $guarding->setValue(null, true);
        try {
            DB::table('settings')->insert(['key' => 'preview_probe_inside', 'value' => '1']);
            $this->fail('a write during a preview went through');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('A preview never writes', $e->getMessage());
        } finally {
            $guarding->setValue(null, false);
        }
        $this->assertFalse(DB::table('settings')->where('key', 'preview_probe_inside')->exists());

        // Outside a preview, writes go on as ever.
        DB::table('settings')->insert(['key' => 'preview_probe', 'value' => '1']);
        $this->assertTrue(DB::table('settings')->where('key', 'preview_probe')->exists());
    }

    public function test_staff_preview_their_unsaved_text_and_send_it_to_themselves(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['email' => 'chris@example.test', 'role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $template = NotificationTemplate::query()->where('key', 'invoice_paid')->sole();
        $sent = [];
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$sent) {
            $sent[] = [$event->message->getTo()[0]->getAddress(), $event->message->getSubject(), $event->message->getHtmlBody()];
        });

        $page = $this->livewireAs($staff)->test(EditNotificationTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm(['body' => 'UNSAVED TEXT for {{ invoice_number }}'])
            ->mountAction('preview')->assertActionMounted('preview');
        $shown = (new \ReflectionMethod(EditNotificationTemplate::class, 'previewHtml'))->invoke($page->instance(), 'en');
        $this->assertStringContainsString('UNSAVED TEXT for 32999', html_entity_decode($shown), 'the preview shows the text on the form');
        $this->assertStringContainsString('<iframe sandbox=""', $shown, 'in a frame of its own');
        $page->unmountAction()->callAction('sendTest')->assertNotified();

        $this->assertNotEmpty($sent);
        $this->assertSame('chris@example.test', $sent[0][0]);
        $this->assertStringStartsWith('[TEST ', $sent[0][1]);
        $this->assertStringContainsString('UNSAVED TEXT for 32999', $sent[0][2] . implode('', array_column($sent, 2)));
        $this->assertStringNotContainsString('UNSAVED', (string) $template->fresh()->body, 'nothing saved');
    }
}
