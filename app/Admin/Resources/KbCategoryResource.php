<?php

namespace App\Admin\Resources;

use App\Admin\Resources\KbCategoryResource\Pages\ManageKbCategories;
use App\Models\KbArticle;
use App\Models\KbCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The knowledgebase's categories (docs/knowledgebase.md); an article may be in several. */
class KbCategoryResource extends Resource
{
    protected static ?string $model = KbCategory::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationParentItem = 'Knowledgebase';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-folder-2-line';

    protected static ?string $navigationLabel = 'Categories';

    protected static ?string $modelLabel = 'Knowledgebase Category';

    protected static ?string $pluralModelLabel = 'Knowledgebase Categories';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            TextInput::make('sort')->label('Order')->numeric()->minValue(0)->maxValue(65535)->default(0),
            Textarea::make('description')->label('Description')->rows(2)->maxLength(1000)->columnSpanFull(),
            Section::make('Translations')->collapsed()->columnSpanFull()
                ->visible(fn () => KbArticle::translationLocales() !== [])
                ->schema(fn () => collect(KbArticle::translationLocales())->flatMap(fn (string $locale) => [
                    TextInput::make("translations.{$locale}.name")->label('Name (' . $locale . ')')->maxLength(255),
                    Textarea::make("translations.{$locale}.description")->label('Description (' . $locale . ')')->rows(2)->maxLength(1000),
                ])->all()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('Name')->searchable()->sortable(),
                TextColumn::make('articles_count')->label('Articles')->counts('articles')->sortable(),
                TextColumn::make('sort')->label('Order')->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageKbCategories::route('/')];
    }
}
