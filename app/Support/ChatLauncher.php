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

    /** The button's look (owner 2026-11-17): the text, our chat icon and the text, or a round button with the icon only. */
    public const STYLES = ['text', 'icon-text', 'icon'];

    /** Our one chat icon: a fixed file, never anyone's upload (§3 ΣΤ). */
    public static function icon(): string
    {
        return trim((string) file_get_contents(resource_path('launcher/chat-icon.svg')));
    }

    /** The look MyCS's own buttons take (Settings → livechat_button_style). */
    public static function ownStyle(): string
    {
        $style = (string) config('settings.livechat_button_style', 'text');

        return in_array($style, self::STYLES, true) ? $style : 'text';
    }

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
     * @return array{site: string, department: int|null, text: string, position: string, colour: string, style: string}|null
     */
    public static function checked(array $choices): ?array
    {
        $site = ChatVisitor::site(is_string($choices['site'] ?? null) ? $choices['site'] : null);
        $department = $choices['department'] ?? null;
        $department = $department === null || $department === '' ? null : (is_numeric($department) ? (int) $department : -1);
        $text = trim((string) ($choices['text'] ?? ''));
        $position = (string) ($choices['position'] ?? '');
        $colour = strtolower((string) ($choices['colour'] ?? ''));
        $style = (string) ($choices['style'] ?? 'text');
        if ($site === null || ($department !== null && !VisitorChats::departments()->contains('id', $department))
            // No braces: Astro, Vue and their kind would run them as code on the site (ADV-890-1); one line, no separators.
            || $text === '' || mb_strlen($text) > self::TEXT_MAX || preg_match('/[\x00-\x1F\x7F\p{Cc}\p{Cf}\p{Zl}\p{Zp}{}]/u', $text) === 1
            || !in_array($position, self::POSITIONS, true) || preg_match('/^#[0-9a-f]{6}$/D', $colour) !== 1
            || !in_array($style, self::STYLES, true)) {
            return null;
        }

        return ['site' => $site, 'department' => $department, 'text' => $text, 'position' => $position, 'colour' => $colour, 'style' => $style];
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
        $look = $checked['style'] ?? 'text';
        $style = ($preview ? '' : "position:fixed;bottom:16px;{$side}:16px;z-index:2147483000;")
            . ($look === 'icon' ? 'width:52px;height:52px;padding:0;border-radius:50%;' : 'padding:10px 18px;border-radius:999px;gap:8px;')
            . 'display:inline-flex;align-items:center;justify-content:center;border:0;background:' . $checked['colour'] . ';color:#fff;'
            . 'font:600 14px/1.2 system-ui,sans-serif;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.25)';
        // The icon is our fixed file; the text, escaped, is the label an icon-only button still says (aria-label, title).
        $inside = match ($look) {
            'icon' => self::icon(),
            'icon-text' => self::icon() . '<span>' . e($checked['text']) . '</span>',
            default => e($checked['text']),
        };
        $label = $look === 'icon' ? ' aria-label="' . e($checked['text']) . '" title="' . e($checked['text']) . '"' : '';

        return '<button type="button" data-mycs-chat="' . e(self::popupUrl($checked)) . '"' . $label . ' style="' . e($style) . '">' . $inside . '</button>';
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
