<?php

namespace App\Models;

use App\Exceptions\DisplayException;
use App\Observers\ClientGroupObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A customer group (ROADMAP 5, as WHMCS's): a name and colour for staff, and a percentage off its members' services,
 * taken in their price after a coupon (OrderPricing::line). On domains only with «Also on domains» (Domain::catalogRenewalPrice, D-23).
 *
 * @property int $id
 * @property string $name
 * @property string|null $colour
 * @property string $discount_percent
 * @property bool $separate_invoices
 * @property bool $dunning_exempt
 * @property bool $no_overdue_reminders
 */
#[ObservedBy([ClientGroupObserver::class])]
class ClientGroup extends Model implements Auditable
{
    use Traits\Auditable;

    protected $fillable = ['name', 'colour', 'discount_percent', 'applies_to_domains', 'separate_invoices', 'dunning_exempt', 'no_overdue_reminders'];

    protected function casts(): array
    {
        return ['discount_percent' => 'decimal:2', 'applies_to_domains' => 'boolean', 'separate_invoices' => 'boolean', 'dunning_exempt' => 'boolean', 'no_overdue_reminders' => 'boolean'];
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** A percentage from 0 to 100, however it is saved (the form, the API, an import; review F4 of #272). */
    protected static function booted(): void
    {
        static::saving(function (ClientGroup $group) {
            if (!is_numeric($group->discount_percent) || (float) $group->discount_percent < 0 || (float) $group->discount_percent > 100) {
                throw new DisplayException(__('A group discount is a percentage from 0 to 100.'));
            }
        });
    }

    /** Its discount for pricing: null when none. */
    public function percent(): ?string
    {
        return (float) $this->discount_percent > 0 ? number_format((float) $this->discount_percent, 2, '.', '') : null;
    }

    /** A hex colour (#rgb, #rrggbb: the column holds 7), the only form that goes into a style (review of #574: \z, no newline). */
    public const COLOUR = '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})\z/i';

    /** Its colour for a style attribute: a hex colour only, else none (staff and imports write it). */
    public function safeColour(): ?string
    {
        return preg_match(self::COLOUR, (string) $this->colour) === 1 ? (string) $this->colour : null;
    }

    /** @return list<string> what it gives its members, as staff read it (owner 2026-11-01, as WHMCS's Client Groups page) */
    public function perks(): array
    {
        return array_values(array_filter([
            $this->percent() !== null ? __(':percent% off services', ['percent' => self::number((float) $this->discount_percent)]) : null,
            $this->separate_invoices ? __('Separate invoices') : null,
            $this->dunning_exempt ? __('No automatic suspension or termination') : null,
            $this->no_overdue_reminders ? __('No overdue reminders') : null,
        ]));
    }

    /** A percentage as the language writes it (12,5 in Greek). */
    public static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return app()->getLocale() === 'el' ? str_replace('.', ',', $text) : $text;
    }

    /**
     * Its name on its colour, as WHMCS marks a group's clients (owner 2026-11-01): the text dark or light, whichever reads
     * on it; no colour, a plain grey badge. Escaped.
     */
    public function badge(?string $text = null): HtmlString
    {
        $text = e($text ?? $this->name);
        $colour = $this->safeColour();
        if ($colour === null) {
            return new HtmlString('<span class="fi-badge inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-white/20">' . $text . '</span>');
        }
        $hex = ltrim($colour, '#');
        $hex = strlen($hex) === 3 ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex;
        [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));
        $ink = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#111827' : '#ffffff';

        return new HtmlString('<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium" style="background-color: ' . $colour
            . '; color: ' . $ink . '">' . $text . '</span>');
    }
}
