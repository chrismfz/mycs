<?php

namespace App\Mail;

use App\Helpers\TemplateRenderer;
use App\Models\NotificationTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A customer mail from a notification template, rendered when it is made (audit sweep EVT-10): the queued job carries
 * the subject and the HTML, not the template's data (whole models, the customer's password hash among them), and
 * sends what the email log shows.
 */
class Mail extends Mailable
{
    use Queueable;

    /** Retried with backoff: a mail server that is down for a while loses nothing. */
    public int $tries = 5;

    /** @var list<int> seconds */
    public array $backoff = [60, 300, 900, 3600];

    public $email_log_id;

    /** The template's key (TicketReplyEmailListener threads ticket replies by it). */
    public string $templateKey;

    public string $renderedSubject;

    public string $renderedHtml;

    /** For a ticket reply: its message, to thread the mail (TicketReplyEmailListener). */
    public ?int $ticketMessageId = null;

    public function __construct(NotificationTemplate $emailTemplate, array $data = [])
    {
        $this->templateKey = $emailTemplate->key;
        $this->renderedSubject = TemplateRenderer::render($emailTemplate->subject, $data, escape: false);
        $this->renderedHtml = TemplateRenderer::render($emailTemplate->body, $data);
        $this->ticketMessageId = isset($data['ticketMessage']) ? $data['ticketMessage']->id : null;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedSubject);
    }

    public function content(): Content
    {
        return new Content(html: 'components.mail.base', with: ['html' => $this->renderedHtml]);
    }
}
