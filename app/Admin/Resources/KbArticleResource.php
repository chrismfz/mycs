<?php

namespace App\Admin\Resources;

use App\Admin\Resources\KbArticleResource\Pages\CreateKbArticle;
use App\Admin\Resources\KbArticleResource\Pages\EditKbArticle;
use App\Admin\Resources\KbArticleResource\Pages\ListKbArticles;
use App\Models\KbArticle;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The knowledgebase's articles (docs/knowledgebase.md): written in the instance's language with the RichEditor (HTML,
 * images on the public disk), translations optional, shown on /knowledgebase/{id}/{slug}.html as WHMCS showed them.
 */
class KbArticleResource extends Resource
{
    protected static ?string $model = KbArticle::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-book-open-line';

    protected static ?string $navigationLabel = 'Knowledgebase';

    protected static ?string $modelLabel = 'Article';

    protected static ?string $pluralModelLabel = 'Knowledgebase Articles';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                TextInput::make('title')->label('Title')->required()->maxLength(255)->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Get $get, Set $set, string $operation) => $operation === 'create' && blank($get('slug'))
                        ? $set('slug', KbArticle::slugFor((string) $state)) : null),
                TextInput::make('slug')->label('Address')->maxLength(255)->alphaDash()
                    ->helperText('After /knowledgebase/{id}/ in its address; from the title when left empty. Another one opens it too (redirected).'),
                Select::make('categories')->label('Categories')->relationship('categories', 'name', fn ($query) => $query->orderBy('sort')->orderBy('name'))
                    ->multiple()->preload()->required(),
                TagsInput::make('tags')->label('Tags')->splitKeys([',', 'Enter']),
                // An article from WHMCS is edited as its HTML at first: the editor keeps only what it knows (WHMCS's
                // classes, styles, iframes would go on any save; code review 4). Staff switch to the editor when they want.
                Toggle::make('html_source')->label('Edit as HTML')->dehydrated(false)->live()->columnSpanFull()
                    ->afterStateHydrated(fn (Toggle $component, ?KbArticle $record) => $component->state($record?->whmcs_id !== null))
                    // Switched, the text goes along: what was written in one is what the other shows (adversarial R2-3).
                    ->afterStateUpdated(function (bool $state, Get $get, Set $set) {
                        foreach (['body' => 'body_source'] + collect(KbArticle::translationLocales())
                            ->mapWithKeys(fn (string $locale) => ["translations.{$locale}.body" => "translations_source.{$locale}"])->all() as $editor => $source) {
                            $state ? $set($source, self::html($get($editor))) : $set($editor, filled($get($source)) ? (string) $get($source) : null);
                        }
                    })
                    ->helperText('The editor rewrites HTML it does not know (classes, styles, embedded frames): keep this on for an imported article.'),
                RichEditor::make('body')->label('Article')->required()->columnSpanFull()->visible(fn (Get $get) => !$get('html_source'))
                    ->fileAttachmentsDisk('public')->fileAttachmentsDirectory('kb')->fileAttachmentsVisibility('public'),
                // Its own field: the editor's, even hidden, holds the article as it rewrote it. Saved as the body by the pages
                // (KbArticleResource::withHtmlSource).
                Textarea::make('body_source')->label('Article (HTML)')->required()->columnSpanFull()->rows(24)->dehydrated(fn (Get $get) => (bool) $get('html_source'))
                    ->visible(fn (Get $get) => (bool) $get('html_source'))->extraInputAttributes(['class' => 'font-mono text-xs'])
                    ->afterStateHydrated(fn (Textarea $component, ?KbArticle $record) => $component->state($record?->body)),
                TextInput::make('sort')->label('Order')->numeric()->minValue(0)->maxValue(65535)->default(0),
                Toggle::make('is_published')->label('Published')->default(true)->inline(false)
                    ->helperText('Off: only staff see it here; its address answers «not found».'),
            ]),
            Section::make('Translations')->collapsed()
                ->description('Optional. A visitor in another language reads the translation when there is one, else the article as written above.')
                ->visible(fn () => KbArticle::translationLocales() !== [])
                ->schema(fn () => collect(KbArticle::translationLocales())->flatMap(fn (string $locale) => [
                    TextInput::make("translations.{$locale}.title")->label('Title (' . $locale . ')')->maxLength(255),
                    RichEditor::make("translations.{$locale}.body")->label('Article (' . $locale . ')')->visible(fn (Get $get) => !$get('html_source'))
                        ->fileAttachmentsDisk('public')->fileAttachmentsDirectory('kb')->fileAttachmentsVisibility('public'),
                    // As the article's own (adversarial R2-2): its HTML kept while edited as HTML.
                    Textarea::make("translations_source.{$locale}")->label('Article (' . $locale . ', HTML)')->rows(16)
                        ->visible(fn (Get $get) => (bool) $get('html_source'))->dehydrated(fn (Get $get) => (bool) $get('html_source'))
                        ->extraInputAttributes(['class' => 'font-mono text-xs'])
                        ->afterStateHydrated(fn (Textarea $component, ?KbArticle $record) => $component->state($record?->translations[$locale]['body'] ?? null)),
                ])->all()),
        ]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> the body as staff wrote it, in HTML or with the editor */
    public static function withHtmlSource(array $data): array
    {
        if (array_key_exists('body_source', $data)) {
            $data['body'] = (string) $data['body_source'];
        }
        foreach ((array) ($data['translations_source'] ?? []) as $locale => $html) {
            $data['translations'][$locale]['body'] = $html;
        }
        unset($data['body_source'], $data['translations_source']);

        return $data;
    }

    /** The editor's state (its document, or HTML) as HTML. */
    private static function html(mixed $state): string
    {
        return is_array($state) ? RichContentRenderer::make($state)->toHtml() : (string) $state;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('title')->label('Title')->searchable()->sortable()->wrap(),
                TextColumn::make('categories.name')->label('Categories')->badge(),
                TextColumn::make('views')->label('Views')->numeric()->sortable(),
                IconColumn::make('is_published')->label('Published')->boolean()->falseIcon('heroicon-o-minus')->falseColor('gray'),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('categories')->label('Category')->relationship('categories', 'name')])
            ->recordActions([
                Action::make('open')->label('Open')->icon('ri-external-link-line')->color('gray')
                    ->url(fn (KbArticle $record) => $record->url(), shouldOpenInNewTab: true)->visible(fn (KbArticle $record) => $record->is_published),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKbArticles::route('/'),
            'create' => CreateKbArticle::route('/create'),
            'edit' => EditKbArticle::route('/{record}/edit'),
        ];
    }
}
