<?php

namespace App\Mail;

use App\Mail\Concerns\LogsDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A logged mail sent again by staff (docs/mail.md): its subject and body as the log keeps them (secrets masked: a
 * welcome mail's password is not sent again). Encrypted in the queue, as every mail.
 */
class ResentMail extends Mailable implements ShouldBeEncrypted
{
    use LogsDelivery, Queueable;

    public int $tries = 5;

    /** @var list<int> seconds */
    public array $backoff = [60, 300, 900, 3600];

    public $email_log_id;

    public function __construct(public string $renderedSubject, public string $renderedHtml) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->renderedHtml);
    }
}
