<?php

namespace App\Support\Tickets;

use App\Models\TicketMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
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
                    'types' => implode(', ', array_keys(Attachments::TYPES))]));
            }
        };
    }

    private static function put(TicketMessage $message, string $filename, string $content): void
    {
        $name = Attachments::safeName($filename);
        $path = self::DIRECTORY . '/' . Str::ulid() . '.' . Attachments::extension($name);
        File::ensureDirectoryExists(storage_path('app/' . self::DIRECTORY));
        file_put_contents(storage_path('app/' . $path), $content);
        try {
            $message->attachments()->create(['path' => $path, 'filename' => $name, 'mime_type' => Attachments::sniff($content), 'filesize' => strlen($content)]);
        } catch (\Throwable $e) {
            @unlink(storage_path('app/' . $path));

            throw $e;
        }
    }
}
