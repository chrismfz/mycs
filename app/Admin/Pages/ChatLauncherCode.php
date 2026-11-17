<?php

namespace App\Admin\Pages;

use App\Models\Staff;
use App\Support\ChatLauncher;
use App\Support\ChatVisitor;
use App\Support\VisitorChats;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Settings → «Code for the site» (docs/livechat.md §4, step 4, PR 4): staff choose a site from the list, a department,
 * the text, the side and the colour; see the button; copy the code. Nothing is saved: to change a button on a site, make
 * the code again and paste it again. The choices are only a form's state; what goes in the code is checked by
 * ChatLauncher on every render.
 */
class ChatLauncherCode extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-chat-smile-2-line';

    protected static ?int $navigationSort = 90;

    protected static string $routePath = 'chat-launcher';

    protected string $view = 'admin.pages.chat-launcher-code';

    public string $site = '';

    public string $department = '';

    public string $text = 'Chat';

    public string $position = 'right';

    public string $colour = '#2563eb';

    public string $form = 'html';

    public static function getNavigationLabel(): string
    {
        return __('Chat launcher code');
    }

    public function getTitle(): string
    {
        return __('Chat launcher code');
    }

    public static function canAccess(): bool
    {
        $staff = Auth::guard('staff')->user();

        return $staff instanceof Staff && $staff->hasPermission('admin.settings.view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->site = (string) collect((array) config('settings.livechat_sites', []))->first();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $checked = ChatLauncher::checked(['site' => $this->site, 'department' => $this->department, 'text' => $this->text,
            'position' => $this->position, 'colour' => $this->colour]);
        $form = in_array($this->form, ChatLauncher::FORMS, true) ? $this->form : 'html';

        return [
            'enabled' => ChatVisitor::guestEnabled(),
            'sites' => array_values(array_filter((array) config('settings.livechat_sites', []), 'is_string')),
            'departments' => VisitorChats::departments(),
            'checked' => $checked,
            'code' => $checked === null ? null : ChatLauncher::snippet($checked, $form),
            'preview' => $checked === null ? null : ChatLauncher::button($checked, preview: true),
            'integrity' => ChatLauncher::integrity(),
            'scriptUrl' => ChatLauncher::scriptUrl(),
        ];
    }
}
