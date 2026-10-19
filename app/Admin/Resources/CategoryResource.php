<?php

namespace App\Admin\Resources;

use App\Admin\Resources\CategoryResource\Pages\CreateCategory;
use App\Admin\Resources\CategoryResource\Pages\EditCategory;
use App\Admin\Resources\CategoryResource\Pages\ListCategories;
use App\Admin\Resources\CategoryResource\RelationManagers\ProductsRelationManager;
use App\Models\Category;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-folder-6-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-folder-6-fill';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                        if (($get('slug') ?? '') !== Str::slug($old)) {
                            return;
                        }

                        $set('slug', Str::slug($state));
                    }),
                TextInput::make('slug')
                    ->required(),
                // The link the company's site uses: it opens the category also when it is not in the menu (hidden).
                TextInput::make('order_link')
                    ->label('Order link')
                    ->readOnly()
                    ->dehydrated(false)
                    ->copyable()
                    ->afterStateHydrated(fn (TextInput $component, ?Category $record) => $component->state($record?->exists ? route('category.show', ['category' => $record]) : null))
                    ->helperText('Its products that can still be ordered are shown by this link, also when none is listed.')
                    ->visibleOn('edit'),
                RichEditor::make('description')
                    ->required(),
                Select::make('parent_id')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Parent Category')
                    // Disallow having same category as it's own parent
                    ->disableOptionWhen(fn (string $value, ?Category $record): bool => $record && (int) $value === $record->id),
                // WHMCS «Hidden» the other way round: off the menu and the home page, still opened by its link.
                Toggle::make('show_in_menu')
                    ->label('Show in menu')
                    ->helperText('Off: not in the shop menu or on the home page; its link still works.')
                    ->default(true),
                FileUpload::make('image')
                    ->label('Image')
                    ->nullable()
                    ->visibility('public')
                    ->disk('public')
                    ->acceptedFileTypes(['image/*'])
                    ->columnSpanFull(),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('show_in_menu')->label('In menu')->boolean(),
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->before(function (DeleteBulkAction $action, $records) {
                        foreach ($records as $record) {
                            if ($record->products()->exists() || $record->children()->exists()) {
                                Notification::make()
                                    ->title('Cannot delete category')
                                    ->body('The category has products or children categories.')
                                    ->duration(5000)
                                    ->icon('ri-error-warning-line')
                                    ->danger()
                                    ->send();
                                $action->cancel();
                            }
                        }
                    }),
                ]),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('sort', 'asc');
            })
            ->reorderable('sort');
    }

    public static function getRelations(): array
    {
        return [
            ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
