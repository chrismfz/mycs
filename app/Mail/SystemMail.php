<?php

namespace App\Mail;

use App\Mail\Concerns\LogsDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SystemMail extends Mailable implements ShouldBeEncrypted
{
    use LogsDelivery, Queueable, SerializesModels;

    /** Retried with backoff: a mail server that is down for a while loses nothing. */
    public int $tries = 5;

    /** @var list<int> seconds */
    public array $backoff = [60, 300, 900, 3600];

    public $email_log_id;

    /** Sent by no person (RFC 3834): a vacation responder or a helpdesk does not answer it (MarkAutomaticMail). */
    public string $autoSubmitted = 'auto-generated';

    public $mail;

    /**
     * Create a new message instance.
     */
    public function __construct(
        $mail,
    ) {
        $this->mail = $mail;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mail['subject'],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $appName = e(config('app.name'));
        // HTML, or escaped text whose lines are kept: never read as Markdown (a customer's text in it stays text).
        $text = (string) $this->mail['body'];
        $content = $text === strip_tags($text) ? nl2br($text) : $text;
        $body = <<<HTML
        <p>Hi,</p>

        {$content}

        <small>This is an automated message sent from {$appName}</small>
        HTML;

        return new Content(
            html: 'components.mail.system',
            with: ['body' => $body],
        );
    }
}
