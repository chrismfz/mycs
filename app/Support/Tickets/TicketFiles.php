<?php

namespace App\Support\Tickets;

use App\Models\TicketMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use MyCS\TicketMail\Attachments;
use MyCS\TicketMail\InboundAttachment;

/**
 * A ticket's files on the private disk (docs/tickets.md §3 ΣΤ), the same rules for every way in: email, the portal, staff.
 * A name of ours (a ULID and the allowed extension), the type of the bytes, always downloaded (TicketAttachmentController).
 */
final class TicketFiles
{
    public const DIRECTORY = 'tickets/uploads';

    /**
     * @param  list<InboundAttachment>  $files  already within the rules (Parser)
     */
    public static function storeInbound(TicketMessage $message, array $files): void
    {
        foreach (array_slice($files, 0, Attachments::MAX_COUNT) as $file) {
            self::put($message, $file->filename, $file->content);
        }
    }

    /** A file uploaded in the portal or the admin, already validated (rule()). */
    public static function storeUpload(TicketMessage $message, UploadedFile $file): void
    {
        self::put($message, $file->getClientOriginalName(), (string) file_get_contents($file->getRealPath()));
    }

    /**
     * The size a file can have in the portal, in MB: the smallest of the ticket rule, Livewire's temporary upload limit
     * (12 MB by default) and PHP's own (upload_max_filesize, post_max_size), so the text says what is really taken.
     */
    public static function maxUploadMegabytes(): int
    {
        $kilobytes = [Attachments::MAX_BYTES / 1024, 12288];
        foreach (FileUploadConfiguration::rules() as $rule) {
            if (is_string($rule) && preg_match('/^max:(\d+)$/', $rule, $m) === 1) {
                $kilobytes[1] = (int) $m[1];
            }
        }
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = self::iniBytes((string) ini_get($setting));
            if ($bytes > 0) {
                $kilobytes[] = $bytes / 1024;
            }
        }

        return max(1, (int) floor(min($kilobytes) / 1024));
    }

    /** A php.ini size («2M», «8M», «1G», bytes) in bytes; 0 for none or unlimited. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** The validation of an upload: the same types (by name and by bytes) and size as by email. */
    public static function rule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (!$value instanceof UploadedFile || !$value->isValid()) {
                $fail(__('validation.file', ['attribute' => __('file')]));

                return;
            }
            $refusal = Attachments::refusal($value->getClientOriginalName(), (string) file_get_contents($value->getRealPath()));
            if ($refusal !== null) {
                $fail(__('ticket.file_refused', ['name' => Attachments::safeName($value->getClientOriginalName()),
                    'types' => implode(', ', array_keys(Attachments::TYPES)), 'size' => self::maxUploadMegabytes()]));
            }
        };
    }

    /** @var list<string>|null the files written in the open transaction() */
    private static ?array $written = null;

    /**
     * A DB transaction whose files go with it: written during it and the transaction rolls back, they are deleted.
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    public static function transaction(\Closure $work): mixed
    {
        $outer = self::$written;
        self::$written = [];
        try {
            $result = DB::transaction($work);
            self::$written = $outer === null ? null : array_merge($outer, self::$written);

            return $result;
        } catch (\Throwable $e) {
            foreach (self::written() as $path) {
                @unlink(storage_path('app/' . $path));
            }
            self::$written = $outer;

            throw $e;
        }
    }

    /** @return list<string> */
    private static function written(): array
    {
        return self::$written ?? [];
    }

    private static function put(TicketMessage $message, string $filename, string $content): void
    {
        $name = Attachments::safeName($filename);
        $path = self::DIRECTORY . '/' . Str::ulid() . '.' . Attachments::extension($name);
        File::ensureDirectoryExists(storage_path('app/' . self::DIRECTORY));
        file_put_contents(storage_path('app/' . $path), $content);
        if (self::$written !== null) {
            self::$written[] = $path;
        }
        try {
            $message->attachments()->create(['path' => $path, 'filename' => $name, 'mime_type' => Attachments::sniff($content), 'filesize' => strlen($content)]);
        } catch (\Throwable $e) {
            @unlink(storage_path('app/' . $path));

            throw $e;
        }
    }
}
