<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Concerns\NamesTheClient;
use App\Models\LiveChat;
use App\Models\Staff;
use App\Support\LiveChats;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Locked;

/**
 * One of the account's chats, the whole conversation read only, staff notes included (docs/livechat.md §6α). The chat
 * must be this account's (else 404) and of a department that lists the member (F3, else 404 too: no hint it exists),
 * checked on every request, not only at mount (F7). Reading it writes nothing: no audit, no seen or unread state (F12).
 */
class ViewChat extends Page
{
    use InteractsWithRecord;
    use NamesTheClient;

    protected static string $resource = ClientResource::class;

    protected string $view = 'admin.resources.client-resource.pages.view-chat';

    /** Not named after the route's `{chat}`: Livewire would fill it from the URL before mount() checks it. */
    #[Locked]
    public int $chatId;

    public function mount(int|string $record, int|string $chat): void
    {
        $this->record = $this->resolveRecord($record);
        // The permission first (403), then whether this chat is one the member may read (404).
        abort_unless(static::canAccess(['record' => $this->getRecord()]), 403);
        abort_unless(ctype_digit((string) $chat), 404);
        $this->chatId = (int) $chat;
        $this->liveChat();
    }

    public function hydrate(): void
    {
        $this->liveChat();
    }

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return LiveChats::isOperator(self::me()) && $record !== null && ClientResource::canView($record);
    }

    public static function getNavigationLabel(): string
    {
        return __('Chats');
    }

    public function getSubheading(): ?string
    {
        return __('Chat #:id', ['id' => $this->chatId]);
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        $client = $this->getRecord();

        return [
            ClientResource::getUrl('index') => ClientResource::getBreadcrumb(),
            ClientResource::getUrl('view', ['record' => $client]) => $this->getTitle(),
            ShowChats::getUrl(['record' => $client]) => __('Chats'),
            __('Chat #:id', ['id' => $this->chatId]),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $chat = $this->liveChat();

        return ['liveChat' => $chat, 'messages' => LiveChats::history($chat)];
    }

    /** This account's chat, when the member may see it; else 404. */
    private function liveChat(): LiveChat
    {
        $chat = LiveChat::query()->with(['department:id,name', 'operator:id,first_name,last_name', 'user:id,first_name,last_name,email'])
            ->whereKey($this->chatId)->where('client_id', $this->getRecord()->getKey())->first();
        abort_unless($chat !== null && LiveChats::canSee(self::me(), $chat), 404);

        return $chat;
    }

    private static function me(): ?Staff
    {
        $staff = auth('staff')->user();

        return $staff instanceof Staff ? $staff : null;
    }
}
