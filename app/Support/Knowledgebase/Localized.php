<?php

namespace App\Support\Knowledgebase;

/**
 * A knowledgebase text in the visitor's language: its columns are the instance's language (settings: app_language), a
 * translation in `translations` ([locale => [field => text]]) is shown when there is one, else the instance's text.
 */
trait Localized
{
    public static function mainLocale(): string
    {
        return (string) config('settings.app_language', config('app.locale', 'en'));
    }

    /** @return list<string> the languages a translation may be written in: the allowed ones but the instance's own */
    public static function translationLocales(): array
    {
        return array_values(array_diff((array) config('settings.allowed_languages', []), [self::mainLocale()]));
    }

    public function localized(string $field): mixed
    {
        $locale = app()->getLocale();
        if ($locale !== self::mainLocale()) {
            $text = $this->translations[$locale][$field] ?? null;
            if (is_string($text) && trim(strip_tags($text)) !== '') {
                return $text;
            }
        }

        return $this->{$field};
    }
}
