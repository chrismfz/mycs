<?php

namespace App\Support;

/** The languages a customer may have (docs/accounts.md): the allowed ones, and WHMCS's names for them. */
final class Locales
{
    /** WHMCS's language names => ours. */
    public const WHMCS = ['english' => 'en', 'greek' => 'el', 'german' => 'de', 'french' => 'fr', 'italian' => 'it', 'spanish' => 'es',
        'dutch' => 'nl', 'portuguese-pt' => 'pt', 'portuguese-br' => 'pt', 'russian' => 'ru', 'turkish' => 'tr', 'arabic' => 'ar',
        'danish' => 'da', 'swedish' => 'sv', 'croatian' => 'hr', 'macedonian' => 'mk', 'norwegian' => 'no', 'hungarian' => 'hu',
        'czech' => 'cs', 'farsi' => 'fa', 'hebrew' => 'he', 'ukranian' => 'uk', 'azerbaijani' => 'az', 'catalan' => 'ca', 'estonian' => 'et'];

    /** WHMCS's name as ours, or null (empty: the instance's; unknown). */
    public static function fromWhmcs(?string $name): ?string
    {
        return self::WHMCS[strtolower(trim((string) $name))] ?? null;
    }

    /** $locale when it is one of the allowed languages, else null (the instance's). */
    public static function allowed(?string $locale): ?string
    {
        return is_string($locale) && $locale !== '' && in_array($locale, (array) config('settings.allowed_languages', []), true) ? $locale : null;
    }

    /** @return array<string, string> the allowed languages, by their own names, for a select */
    public static function options(): array
    {
        $names = (array) config('app.available_locales', []);

        return collect((array) config('settings.allowed_languages', []))->mapWithKeys(fn ($locale) => [(string) $locale => (string) ($names[$locale] ?? $locale)])->all();
    }

    public static function main(): string
    {
        return (string) config('settings.app_language', config('app.locale', 'en'));
    }
}
