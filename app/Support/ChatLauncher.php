<?php

namespace App\Support;

/**
 * The code of the chat button for our sites (docs/livechat.md §4, step 4, PR 4; the owner chose the generator): a
 * button and a few lines of script that open the popup /chat. It holds no secret and passes no identity; it fetches
 * nothing, sets no cookie and reads nothing from the page but location.pathname. Every value staff choose is checked
 * here against a strict form and escaped where it goes, so a generated snippet can carry nothing else (§3 Α).
 *
 * Three forms: inline HTML with the script in it (the default: nothing loads from our origin, so a compromised MyCS runs
 * nothing on the sites), the same as an Astro component, or the button with a versioned script of ours under SRI, for a
 * site whose CSP allows no inline script. A new version of the script is a new file (VERSION), never a changed one.
 */
class ChatLauncher
{
    public const VERSION = 1;

    public const POSITIONS = ['right', 'left'];

    public const FORMS = ['html', 'astro', 'script'];

    public const TEXT_MAX = 30;

    /** The script as it is served and as the inline forms carry it. */
    public static function script(): string
    {
        return (string) file_get_contents(resource_path('launcher/chat-launcher-v' . self::VERSION . '.js'));
    }

    /** Its Subresource Integrity, for the `<script src>` form. */
    public static function integrity(): string
    {
        return 'sha384-' . base64_encode(hash('sha384', self::script(), true));
    }

    public static function scriptUrl(): string
    {
        return route('chat.launcher', ['version' => self::VERSION]);
    }

    /**
     * The choices, as they may go in: a listed site, an offered department or none, a short line of text, a side, a
     * colour #rrggbb. Null when any is not.
     *
     * @param  array<string, mixed>  $choices
     * @return array{site: string, department: int|null, text: string, position: string, colour: string}|null
     */
    public static function checked(array $choices): ?array
    {
        $site = ChatVisitor::site(is_string($choices['site'] ?? null) ? $choices['site'] : null);
        $department = $choices['department'] ?? null;
        $department = $department === null || $department === '' ? null : (is_numeric($department) ? (int) $department : -1);
        $text = trim((string) ($choices['text'] ?? ''));
        $position = (string) ($choices['position'] ?? '');
        $colour = strtolower((string) ($choices['colour'] ?? ''));
        if ($site === null || ($department !== null && !VisitorChats::departments()->contains('id', $department))
            || $text === '' || mb_strlen($text) > self::TEXT_MAX || preg_match('/[\x00-\x1F\x7F\p{Cc}\p{Cf}]/u', $text) === 1
            || !in_array($position, self::POSITIONS, true) || preg_match('/^#[0-9a-f]{6}$/', $colour) !== 1) {
            return null;
        }

        return ['site' => $site, 'department' => $department, 'text' => $text, 'position' => $position, 'colour' => $colour];
    }

    /** The popup's address the button opens: the site's label and the department asked for (a wish: F17). */
    public static function popupUrl(array $checked): string
    {
        return route('chat', array_filter(['src' => $checked['site'], 'dept' => $checked['department']], fn ($value) => $value !== null));
    }

    /** The button alone, escaped; $preview places it in the page instead of fixed on the screen. */
    public static function button(array $checked, bool $preview = false): string
    {
        $side = $checked['position'] === 'left' ? 'left' : 'right';
        $style = ($preview ? '' : "position:fixed;bottom:16px;{$side}:16px;z-index:2147483000;")
            . 'padding:10px 18px;border:0;border-radius:999px;background:' . $checked['colour'] . ';color:#fff;'
            . 'font:600 14px/1.2 system-ui,sans-serif;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.25)';

        return '<button type="button" data-mycs-chat="' . e(self::popupUrl($checked)) . '" style="' . e($style) . '">' . e($checked['text']) . '</button>';
    }

    /** The code to paste, in one of FORMS. */
    public static function snippet(array $checked, string $form): string
    {
        $button = self::button($checked);
        $script = rtrim(self::script());

        return match ($form) {
            'astro' => "---\n// MyCS chat launcher v" . self::VERSION . " (docs/livechat.md §4). Generated: change it in MyCS, not here.\n---\n"
                . $button . "\n<script is:inline>\n" . $script . "\n</script>\n",
            'script' => '<!-- MyCS chat launcher v' . self::VERSION . " -->\n" . $button . "\n"
                . '<script src="' . e(self::scriptUrl()) . '" integrity="' . e(self::integrity()) . '" crossorigin="anonymous" defer></script>' . "\n",
            default => '<!-- MyCS chat launcher v' . self::VERSION . " -->\n" . $button . "\n<script>\n" . $script . "\n</script>\n",
        };
    }
}
