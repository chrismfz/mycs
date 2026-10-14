<?php

namespace App\Models;

use App\Enums\NotificationEnabledStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class NotificationTemplate extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = [
        'key',
        'name',
        'subject',
        'enabled',
        'body',
        'cc',
        'bcc',
        'mail_enabled',
        'in_app_enabled',
        'in_app_title',
        'in_app_body',
        'edit_preference_message',
        'translations',
    ];

    /** The languages templates are translated to, on top of the default (English) text: the ones we support. */
    public const TRANSLATION_LOCALES = ['el'];

    /** The texts a translation may replace. */
    public const TRANSLATED = ['subject', 'body', 'in_app_title', 'in_app_body'];

    protected $casts = [
        'enabled' => 'boolean',
        'cc' => 'array',
        'bcc' => 'array',
        'mail_enabled' => NotificationEnabledStatus::class,
        'in_app_enabled' => NotificationEnabledStatus::class,
        'translations' => 'array',
    ];

    /**
     * This template with its texts in $locale where it has them (a field left empty there keeps the default text).
     * Not saved: a copy to render.
     */
    public function inLocale(string $locale): static
    {
        $texts = $this->translations[$locale] ?? [];
        $copy = clone $this;
        foreach (self::TRANSLATED as $field) {
            if (filled($texts[$field] ?? null)) {
                $copy->{$field} = $texts[$field];
            }
        }

        return $copy;
    }

    /** @return HasMany<NotificationPreference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function isEmailUserControllable()
    {
        return in_array($this->mail_enabled, [NotificationEnabledStatus::ChoiceOn, NotificationEnabledStatus::ChoiceOff]);
    }

    public function isInAppUserControllable()
    {
        return in_array($this->in_app_enabled, [NotificationEnabledStatus::ChoiceOn, NotificationEnabledStatus::ChoiceOff]);
    }

    // Check if user has enabled this notification for email
    public function isEnabledForPreference(?NotificationPreference $preference = null, $type = 'mail')
    {
        $type = $type === 'app' ? 'in_app_enabled' : 'mail_enabled';
        if ($this->{$type} === NotificationEnabledStatus::Force) {
            return true;
        }
        if ($this->{$type} === NotificationEnabledStatus::Never) {
            return false;
        }

        if ($preference) {
            return $preference->{$type};
        }

        // Return true if choice_on, false if choice_off
        return $this->{$type} === NotificationEnabledStatus::ChoiceOn;
    }
}
