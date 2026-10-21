<?php

namespace App\Mail;

use App\Helpers\TemplateRenderer;
use App\Mail\Concerns\LogsDelivery;
use App\Models\NotificationTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A customer mail from a notification template, rendered when it is made (audit sweep EVT-10): the queued job carries
 * the subject and the HTML, not the template's data (whole models, the customer's password hash among them), and
 * sends what the email log shows. Encrypted in the queue (and in failed_jobs): the welcome mail carries a password
 * (sweep §6 #11).
 */
class Mail extends Mailable implements ShouldBeEncrypted
{
    use LogsDelivery {
        send as private sendLogged;
    }
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

    /** A ticket's mail: the Message-ID of ours it goes out with (TicketMailId, docs/tickets.md A3), the same on a retry. */
    public ?string $threadMessageId = null;

    /** A mass mail's news: its «stop these emails», also as the List-Unsubscribe header (RFC 8058 one-click: #294). */
    public ?string $unsubscribeUrl = null;

    public function __construct(NotificationTemplate $emailTemplate, array $data = [])
    {
        $this->templateKey = $emailTemplate->key;
        $this->renderedSubject = TemplateRenderer::render($emailTemplate->subject, $data, escape: false);
        $this->renderedHtml = TemplateRenderer::render($emailTemplate->body, $data);
        $this->ticketMessageId = isset($data['ticketMessage']) ? $data['ticketMessage']->id : null;
        $this->threadMessageId = isset($data['thread_message_id']) && is_string($data['thread_message_id']) ? $data['thread_message_id'] : null;
        $this->unsubscribeUrl = $emailTemplate->key === 'announcement_news' && is_string($data['unsubscribe_url'] ?? null) ? $data['unsubscribe_url'] : null;
    }

    /**
     * Its List-Unsubscribe headers (news only). Not Mailable::headers(): that one is hydrated into a closure when the mail
     * is rendered for its log, before it is queued, and a closure cannot be queued; these are added when it is sent.
     *
     * @return array<string, string>
     */
    public function unsubscribeHeaders(): array
    {
        return $this->unsubscribeUrl === null ? [] : [
            'List-Unsubscribe' => '<' . $this->unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    public function send($mailer)
    {
        $headers = $this->unsubscribeHeaders();
        if ($headers !== []) {
            $this->withSymfonyMessage(static function ($message) use ($headers) {
                foreach ($headers as $name => $value) {
                    $message->getHeaders()->addTextHeader($name, $value);
                }
            });
        }

        return $this->sendLogged($mailer);
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
