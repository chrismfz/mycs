<?php

namespace App\Services\BankAlerts;

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Message;
use RuntimeException;

/**
 * The alerts mailbox over IMAP, its messages untouched: fetched with BODY.PEEK (leaveUnread), so no \Seen; nothing moved,
 * flagged, deleted or expunged (LOGOUT, never CLOSE). The library SELECTs the folder to fetch (not EXAMINE), which only
 * clears the server's \Recent marks.
 */
final class ImapAlertMailbox implements AlertMailbox
{
    private ?Mailbox $mailbox = null;

    /** @param  array{host: string, port: int, encryption: string, username: string, password: string}  $settings */
    public function __construct(#[\SensitiveParameter] private readonly array $settings) {}

    public function status(string $folder): array
    {
        $status = $this->folder($folder)->status();

        return ['validity' => (int) ($status['UIDVALIDITY'] ?? 0), 'next' => (int) ($status['UIDNEXT'] ?? 0)];
    }

    public function messages(string $folder, int $from, int $limit): iterable
    {
        $query = $this->folder($folder)->messages()->withHeaders()->withBody()->leaveUnread()->oldest()->limit($limit);
        $query->uid(max(1, $from), INF); // a search condition, forwarded to the query builder
        $messages = $query->get();
        foreach ($messages as $message) {
            if ($message instanceof Message && $message->uid() >= $from) {
                yield [$message->uid(), (string) $message];
            }
        }
    }

    public function disconnect(): void
    {
        try {
            $this->mailbox?->disconnect();
        } catch (\Throwable) {
        }
        $this->mailbox = null;
    }

    private function folder(string $name): FolderInterface
    {
        if ($this->mailbox === null) {
            $this->mailbox = new Mailbox([
                'host' => $this->settings['host'], 'port' => $this->settings['port'],
                'encryption' => $this->settings['encryption'], 'validate_cert' => true,
                'username' => $this->settings['username'], 'password' => $this->settings['password'], 'timeout' => 30,
            ]);
            $this->mailbox->connect();
        }

        return $this->mailbox->folders()->find($name) ?? throw new RuntimeException("No folder «{$name}» in the alerts mailbox.");
    }
}
