<?php

namespace App\Admin\Resources;

use App\Admin\Resources\ChatNotificationRuleResource\Pages\CreateChatNotificationRule;
use App\Admin\Resources\ChatNotificationRuleResource\Pages\EditChatNotificationRule;
use App\Admin\Resources\ChatNotificationRuleResource\Pages\ListChatNotificationRules;
use App\Jobs\SendChatNotification;
use App\Models\ChatNotificationRule;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

/**
 * Setup → Chat notifications (WHMCS «Notifications» to Mattermost; sweep of myip, 2026-10-25): incoming webhooks of
 * Mattermost or Slack and the events each is told of. The URL is a secret: stored encrypted, never shown again; left
 * empty on edit, it is kept.
 */
class ChatNotificationRuleResource extends Resource
{
    protected static ?string $model = ChatNotificationRule::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-chat-1-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Chat notifications';

    protected static ?string $modelLabel = 'chat notification';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255)->placeholder('Billing channel'),
            TextInput::make('webhook_url')->label(__('Incoming webhook URL'))->password()->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->rule('url')->rule('starts_with:https://')->maxLength(2000)
                ->placeholder(fn (string $operation) => $operation === 'edit' ? __('Stored: leave empty to keep it') : 'https://chat.example.com/hooks/…')
                ->helperText(__('Mattermost: Integrations → Incoming Webhooks. Slack: an app\'s incoming webhook. Kept encrypted.')),
            CheckboxList::make('events')->label(__('Tell of'))->required()->columns(2)
                ->options(collect(ChatNotificationRule::EVENTS)->map(fn (string $label) => __($label))->all()),
            Toggle::make('enabled')->label(__('Enabled'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('events')->label(__('Tell of'))->badge()
                    ->formatStateUsing(fn (string $state) => __(ChatNotificationRule::EVENTS[$state] ?? $state)),
                IconColumn::make('enabled')->label(__('Enabled'))->boolean(),
            ])
            ->recordActions([
                Action::make('test')->label(__('Send test'))->icon('ri-send-plane-line')->color('gray')
                    ->authorize('update')
                    ->action(function (ChatNotificationRule $record) {
                        try {
                            SendChatNotification::post((string) $record->webhook_url, '👋 ' . __('MyCS: a test of «:name».', ['name' => $record->name]));
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('Not sent'))->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title(__('Sent'))->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChatNotificationRules::route('/'),
            'create' => CreateChatNotificationRule::route('/create'),
            'edit' => EditChatNotificationRule::route('/{record}/edit'),
        ];
    }
}
