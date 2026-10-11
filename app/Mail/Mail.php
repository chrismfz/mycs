<?php

namespace App\Mail;

use App\Helpers\TemplateRenderer;
use App\Models\NotificationTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class Mail extends Mailable
{
    use Queueable, SerializesModels;

    public $email_log_id;

    public array $data = [];

    public NotificationTemplate $emailTemplate;

    /**
     * Create a new message instance.
     */
    public function __construct(
        NotificationTemplate $emailTemplate,
        array $data = []
    ) {
        $this->emailTemplate = $emailTemplate;
        $this->data = $data;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: TemplateRenderer::render($this->emailTemplate->subject, $this->data, escape: false),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            html: 'components.mail.base',
            // The data stays in the view data: listeners (e.g. TicketReplyEmailListener) read it.
            with: [...$this->data, 'html' => TemplateRenderer::render($this->emailTemplate->body, $this->data)],
        );
    }
}
