<?php

namespace App\Admin\Resources;

use App\Admin\Resources\NotificationTemplateResource\Pages\CreateNotificationTemplate;
use App\Admin\Resources\NotificationTemplateResource\Pages\EditNotificationTemplate;
use App\Admin\Resources\NotificationTemplateResource\Pages\ListNotificationTemplates;
use App\Enums\NotificationEnabledStatus;
use App\Helpers\TemplateRenderer;
use App\Models\NotificationTemplate;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-mail-settings-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-mail-settings-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Other';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->required()
                    ->disabledOn('edit')
                    ->maxLength(255),
                Toggle::make('enabled')
                    ->required(),
                TextInput::make('edit_preference_message')
                    ->label('Edit Preference Message')
                    ->hint('This message will be shown to users when they edit their notification preferences for this template.')
                    ->columnSpanFull(),
                Section::make('Email Template')
                    ->description('Markdown or HTML. Values: {{ invoice.number }}, {{ service.product.name }}, {{ urls.invoice }}; blocks: {% if … %}…{% else %}…{% endif %}, {% for item in items %}…{% endfor %}; filters: date("F j, Y"), default("…"), upper, lower, raw. No PHP or Blade.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('subject')
                            ->required()
                            ->rule(TemplateRenderer::rule())
                            ->maxLength(255),
                        Select::make('mail_enabled')
                            ->label('Mail Enabled')
                            ->options([
                                NotificationEnabledStatus::ChoiceOn->value => 'User Choice, Default On',
                                NotificationEnabledStatus::ChoiceOff->value => 'User Choice, Default Off',
                                NotificationEnabledStatus::Force->value => 'Force On',
                                NotificationEnabledStatus::Never->value => 'Force Off',
                            ])
                            ->required(),
                        MarkdownEditor::make('body')
                            ->hint('Use either Markdown or HTML to compose the email body.')
                            ->disableAllToolbarButtons()
                            ->required()
                            ->rule(TemplateRenderer::rule())
                            ->columnSpanFull(),
                        TagsInput::make('cc')
                            ->placeholder('mail@example.com')
                            ->nestedRecursiveRules(['required', 'email']),
                        TagsInput::make('bcc')
                            ->nestedRecursiveRules(['required', 'email'])
                            ->placeholder('mail@example.com'),
                    ]),
                Section::make('In-App Notification (push)')
                    ->description('Define the title and body of the in-app notification that users will receive.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('in_app_title')
                            ->label('In-App Title')
                            ->rule(TemplateRenderer::rule())
                            ->required(fn (Get $get) => $get('in_app_enabled') !== NotificationEnabledStatus::Never->value)
                            ->disabled(fn (Get $get) => $get('in_app_enabled') === NotificationEnabledStatus::Never->value)
                            ->maxLength(255),
                        Select::make('in_app_enabled')
                            ->label('In-App Enabled')
                            ->options([
                                NotificationEnabledStatus::ChoiceOn->value => 'User Choice, Default On',
                                NotificationEnabledStatus::ChoiceOff->value => 'User Choice, Default Off',
                                NotificationEnabledStatus::Force->value => 'Force On',
                                NotificationEnabledStatus::Never->value => 'Force Off',
                            ])
                            ->live()
                            ->required(),
                        TextInput::make('in_app_body')
                            ->label('In-App Body')
                            ->rule(TemplateRenderer::rule())
                            ->required(fn (Get $get) => $get('in_app_enabled') !== NotificationEnabledStatus::Never->value)
                            ->disabled(fn (Get $get) => $get('in_app_enabled') === NotificationEnabledStatus::Never->value)
                            ->columnSpanFull(),
                        TextInput::make('in_app_url')
                            ->label('In-App URL')
                            ->maxLength(255)
                            ->rule(TemplateRenderer::rule())
                            ->placeholder('{{ urls.invoice }}')
                            ->hint('urls.invoice, urls.service, urls.ticket, urls.services, urls.new_ticket, urls.security')
                            ->disabled(fn (Get $get) => $get('in_app_enabled') === NotificationEnabledStatus::Never->value)
                            ->columnSpanFull(),
                    ]),
                Section::make('Translations')
                    ->description('The texts above in other languages. A mail goes out in the customer\'s language (for now the company\'s default language, Settings → Default Language); a field left empty uses the text above.')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Tabs::make('translations')->tabs(array_map(fn (string $locale) => Tab::make(strtoupper($locale))->schema([
                            TextInput::make("translations.{$locale}.subject")->label('Subject')->rule(TemplateRenderer::rule())->maxLength(255),
                            MarkdownEditor::make("translations.{$locale}.body")->label('Body')->disableAllToolbarButtons()->rule(TemplateRenderer::rule()),
                            TextInput::make("translations.{$locale}.in_app_title")->label('In-App Title')->rule(TemplateRenderer::rule())->maxLength(255),
                            TextInput::make("translations.{$locale}.in_app_body")->label('In-App Body')->rule(TemplateRenderer::rule()),
                        ]), self::translationLocales())),
                    ])
                    ->visible(fn () => self::translationLocales() !== []),
            ]);
    }

    /** @return list<string> the languages other than the default text's (English) */
    private static function translationLocales(): array
    {
        return NotificationTemplate::TRANSLATION_LOCALES;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject')
                    ->searchable(),
                IconColumn::make('enabled')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'create' => CreateNotificationTemplate::route('/create'),
            'edit' => EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
