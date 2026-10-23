<?php

namespace App\Support;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\StaffResource;
use App\Admin\Resources\UserResource;
use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Contact;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * An audit as one line of the Activity Log, as WHMCS writes it: «Modified Domain example.gr — Expiry date: 2026-10-31 →
 * 2027-10-31», with the client it is about and who did it (owner 2026-10-23). The values are those the audit's own
 * page shows (AuditValue: redacted where the model redacts them), only shorter.
 */
final class AuditSummary
{
    /** The models whose audits name a client through their own client(): loaded with the page. */
    public const WITH_CLIENT = [
        Service::class, Domain::class, Invoice::class, Ticket::class,
        Order::class, Contact::class, ClientNote::class, Credit::class,
    ];

    /** Kept by every model, said by none: left out of the line (the audit's page still has them). */
    private const QUIET = ['created_at', 'updated_at'];

    /** Shown in a line at most: the rest is counted, and the audit's page has it all. */
    private const MAX_CHANGES = 6;

    /** A subject's name when its resource has none: the first of these it has. */
    private const NAMES = ['fqdn', 'name', 'label', 'number', 'subject', 'title', 'key', 'code', 'email'];

    public static function line(Audit $audit): string
    {
        $line = self::verb($audit->event) . ' ' . self::subject($audit);
        $changes = self::changes($audit);

        return $changes === '' ? $line : $line . ' — ' . $changes;
    }

    public static function verb(?string $event): string
    {
        return match ($event) {
            'created' => __('Created'),
            'updated' => __('Modified'),
            'deleted' => __('Deleted'),
            'restored' => __('Restored'),
            default => __(Str::headline((string) $event)),
        };
    }

    /** «Domain example.gr», «Service Hosting S (#5)», or by its id alone. */
    public static function subject(Audit $audit): string
    {
        $type = __(Str::headline(class_basename((string) $audit->auditable_type)));
        $name = self::name($audit);

        return $name === null ? "{$type} #{$audit->auditable_id}" : "{$type} {$name}";
    }

    /** «Expiry date: 2026-10-31 → 2027-10-31; Status: pending → active», at most MAX_CHANGES of them. */
    public static function changes(Audit $audit): string
    {
        $parts = [];
        foreach (AuditValue::modified($audit) as $key => $value) {
            if (in_array($key, self::QUIET, true)) {
                continue;
            }
            $old = self::value($value['old'] ?? null);
            $new = self::value($value['new'] ?? null);
            if ($old === $new) {
                continue;
            }
            $label = Str::ucfirst(Str::lower(Str::headline((string) $key)));
            $parts[] = match (true) {
                $audit->event === 'created' || !array_key_exists('old', $value) => "{$label}: {$new}",
                $audit->event === 'deleted' || !array_key_exists('new', $value) => "{$label}: {$old}",
                default => "{$label}: " . ($old === '' ? '—' : $old) . ' → ' . ($new === '' ? '—' : $new),
            };
        }
        // A creation lists only what it was given.
        if ($audit->event === 'created') {
            $parts = array_values(array_filter($parts, fn (string $part) => !str_ends_with($part, ': ')));
        }
        $more = count($parts) - self::MAX_CHANGES;
        $parts = array_slice($parts, 0, self::MAX_CHANGES);

        return implode('; ', $parts) . ($more > 0 ? ' ' . __('(+:count more)', ['count' => $more]) : '');
    }

    /** The client the change is about: the client itself, or the one its subject belongs to. */
    public static function client(Audit $audit): ?Client
    {
        $subject = $audit->auditable;
        if ($subject instanceof Client) {
            return $subject;
        }
        if ($subject !== null && in_array($subject::class, self::WITH_CLIENT, true)) {
            $client = $subject->getRelationValue('client');

            return $client instanceof Client ? $client : null;
        }

        return null;
    }

    /** Who did it: a staff member, a customer, or the system (the cron, a queued job). */
    public static function actor(Audit $audit): string
    {
        $user = $audit->user;
        if ($user instanceof Staff || $user instanceof User) {
            return (string) $user->name;
        }

        return $audit->user_id === null ? __('System') : '#' . $audit->user_id;
    }

    public static function actorKind(Audit $audit): ?string
    {
        return match (true) {
            $audit->user_id === null => null,
            $audit->user_type === Staff::class || $audit->user instanceof Staff => __('Staff'),
            default => __('Client'),
        };
    }

    public static function actorUrl(Audit $audit): ?string
    {
        return match (true) {
            $audit->user instanceof Staff => self::url(StaffResource::class, $audit->user, 'edit'),
            $audit->user instanceof User => self::url(UserResource::class, $audit->user, 'edit'),
            default => null,
        };
    }

    public static function clientUrl(?Client $client): ?string
    {
        return $client === null ? null : self::url(ClientResource::class, $client, 'view');
    }

    /** The subject's own page, while it is there and the staff member may open it. */
    public static function subjectUrl(Audit $audit): ?string
    {
        $subject = $audit->auditable;
        if ($subject === null || $audit->event === 'deleted') {
            return null;
        }
        $resource = Filament::getModelResource($subject);
        if ($resource === null) {
            return null;
        }

        return self::url($resource, $subject, $resource::hasPage('view') ? 'view' : 'edit');
    }

    /** @param class-string<resource> $resource */
    private static function url(string $resource, Model $record, string $page): ?string
    {
        try {
            if (!$resource::hasPage($page) || !$resource::canAccess()) {
                return null;
            }

            return $resource::getUrl($page, ['record' => $record]);
        } catch (Throwable) {
            return null; // a resource outside this panel: a line without a link
        }
    }

    private static function name(Audit $audit): ?string
    {
        $subject = $audit->auditable;
        if ($subject !== null) {
            $resource = Filament::getModelResource($subject);
            if ($resource !== null) {
                try {
                    $title = $resource::getRecordTitle($subject);
                    $title = $title === null ? null : strip_tags(is_string($title) ? $title : $title->toHtml());
                    // Filament's default title is the model's own label: no name at all.
                    if (filled($title) && $title !== $resource::getTitleCaseModelLabel()) {
                        return Str::limit($title, 60) . " (#{$subject->getKey()})";
                    }
                } catch (Throwable) {
                    // the names below
                }
            }
            $values = $subject->attributesToArray();
        } else {
            // Deleted: its name as the audits kept it.
            $values = [...(array) ($audit->new_values ?? []), ...(array) ($audit->old_values ?? [])];
        }
        foreach (self::NAMES as $key) {
            $value = $values[$key] ?? null;
            if (is_scalar($value) && filled($value)) {
                return Str::limit((string) $value, 60) . " (#{$audit->auditable_id})";
            }
        }

        return null;
    }

    /** A value as short as it reads: a date without its midnight, a long text cut. */
    private static function value(mixed $value): string
    {
        $text = AuditValue::text($value);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}):\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $text, $m) === 1) {
            return $m[2] === '00:00' ? $m[1] : "{$m[1]} {$m[2]}";
        }

        return Str::limit(Str::squish($text), 60);
    }
}
