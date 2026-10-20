<?php

namespace MyCS\TicketMail\Imap;

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Mailbox as ImapEngine;
use DirectoryTree\ImapEngine\Message;
use MyCS\TicketMail\InboundEmail;
use MyCS\TicketMail\Parser;
use Throwable;

/**
 * The real mailbox, over directorytree/imapengine (pure PHP). Headers and sizes first, then each body by itself, so
 * memory holds one message at a time; one over MAX_BYTES is never downloaded, only its headers (the router quarantines
 * it). Nothing is marked read by fetching (BODY.PEEK); \Seen only after the handler kept it. Untested here by design
 * (it needs a server): the department's «Test connection» is how a real mailbox is checked.
 */
final class ImapEngineMailbox implements Mailbox
{
    /** A whole message we download and parse: room for the 25 MB of files in base64 and the text. */
    public const MAX_BYTES = 40 * 1024 * 1024;

    public function test(MailboxSettings $settings): TestResult
    {
        $mailbox = null;
        try {
            $mailbox = $this->connect($settings);
            $folder = $mailbox->folders()->find($settings->folder);
            if ($folder === null) {
                return new TestResult(false, "No folder «{$settings->folder}».");
            }
            $messages = $folder->messages()->count();
            $query = $folder->messages();
            $query->unseen(); // the builder's, through the query (it returns the query, not the builder)
            $unseen = $query->count();

            return new TestResult(true, "Connected: {$messages} messages, {$unseen} unseen in «{$settings->folder}».", $messages, $unseen);
        } catch (Throwable $e) {
            return new TestResult(false, 'Connection failed: ' . self::scrub($e->getMessage(), $settings));
        } finally {
            self::close($mailbox);
        }
    }

    public function poll(MailboxSettings $settings, callable $handle, int $limit = 50): PollSummary
    {
        $summary = new PollSummary;
        $mailbox = null;
        try {
            $mailbox = $this->connect($settings);
            $summary->connected = true;
            $folder = $mailbox->folders()->find($settings->folder);
            if ($folder === null) {
                $summary->error("No folder «{$settings->folder}».");

                return $summary;
            }
            $query = $folder->messages()->withHeaders()->withSize()->leaveUnread()->oldest()->limit($limit);
            $query->unseen();
            $heads = $query->get();
            foreach ($heads as $head) {
                if (!$head instanceof Message) {
                    continue; // only the library's own (a fake mailbox is a Mailbox of its own)
                }
                $summary->fetched++;
                try {
                    if ($this->handleOne($folder, $head, $handle)) {
                        $summary->handled++;
                    }
                } catch (Throwable $e) {
                    $summary->error('Message ' . $head->uid() . ': ' . self::scrub($e->getMessage(), $settings));
                }
            }
        } catch (Throwable $e) {
            $summary->error('Mailbox: ' . self::scrub($e->getMessage(), $settings));
        } finally {
            self::close($mailbox);
        }

        return $summary;
    }

    /** @param  callable(?InboundEmail, string): bool  $handle */
    private function handleOne(FolderInterface $folder, Message $head, callable $handle): bool
    {
        $size = $head->size();
        // A size we could not read is not taken for a small one: headers only.
        if ($size === null || $size > self::MAX_BYTES) {
            $raw = rtrim($head->head());
            $email = $this->parsed(fn () => Parser::headersOnly($raw, (int) $size));
        } else {
            $full = $folder->messages()->withHeaders()->withBody()->leaveUnread()->find($head->uid());
            if ($full === null) {
                return false;
            }
            $raw = (string) $full;
            $email = $this->parsed(fn () => Parser::parse($raw));
        }

        if ($handle($email, $raw) !== true) {
            return false;
        }
        $head->markSeen();

        return true;
    }

    private function parsed(callable $parse): ?InboundEmail
    {
        try {
            return $parse();
        } catch (Throwable) {
            return null; // the handler keeps it as unparseable, so it never wedges the mailbox
        }
    }

    private function connect(MailboxSettings $settings): ImapEngine
    {
        $encryption = strtolower($settings->encryption) === 'starttls' ? 'starttls' : 'ssl';
        $mailbox = new ImapEngine([
            'host' => $settings->host,
            'port' => $settings->port,
            'encryption' => $encryption,
            'validate_cert' => true,
            'username' => $settings->username,
            'password' => $settings->password,
            'timeout' => $settings->timeout,
        ]);
        $mailbox->connect();

        return $mailbox;
    }

    private static function close(?ImapEngine $mailbox): void
    {
        try {
            $mailbox?->disconnect();
        } catch (Throwable) {
        }
    }

    /** An error as staff may read it: never the password or the login. */
    private static function scrub(string $message, MailboxSettings $settings): string
    {
        return str_replace(array_filter([$settings->password, $settings->username]), '********', $message);
    }
}
