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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
                RichEditor::make('body')->label('Article')->required()->columnSpanFull()
                    ->fileAttachmentsDisk('public')->fileAttachmentsDirectory('kb')->fileAttachmentsVisibility('public'),
                TextInput::make('sort')->label('Order')->numeric()->minValue(0)->maxValue(65535)->default(0),
                Toggle::make('is_published')->label('Published')->default(true)->inline(false)
                    ->helperText('Off: only staff see it here; its address answers «not found».'),
            ]),
            Section::make('Translations')->collapsed()
                ->description('Optional. A visitor in another language reads the translation when there is one, else the article as written above.')
                ->visible(fn () => KbArticle::translationLocales() !== [])
                ->schema(fn () => collect(KbArticle::translationLocales())->flatMap(fn (string $locale) => [
                    TextInput::make("translations.{$locale}.title")->label('Title (' . $locale . ')')->maxLength(255),
                    RichEditor::make("translations.{$locale}.body")->label('Article (' . $locale . ')')
                        ->fileAttachmentsDisk('public')->fileAttachmentsDirectory('kb')->fileAttachmentsVisibility('public'),
                ])->all()),
        ]);
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
