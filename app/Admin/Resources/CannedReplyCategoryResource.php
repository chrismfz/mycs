<?php

namespace App\Admin\Resources;

use App\Admin\Resources\CannedReplyCategoryResource\Pages\ManageCannedReplyCategories;
use App\Models\CannedReplyCategory;
use App\Models\Ticket;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The categories of the predefined replies (docs/tickets.md §6ι): renamed, ordered, deleted (their replies go to General). */
class CannedReplyCategoryResource extends Resource
{
    protected static ?string $model = CannedReplyCategory::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    /** Under Tickets for staff who see them; else on its own (Filament drops a child whose parent is not there). */
    public static function getNavigationParentItem(): ?string
    {
        return auth('staff')->user()?->can('viewAny', Ticket::class) ? 'Tickets' : null;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'ri-folder-line';

    protected static ?string $navigationLabel = 'Reply Categories';

    protected static ?string $modelLabel = 'Reply Category';

    protected static ?string $pluralModelLabel = 'Reply Categories';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            TextInput::make('sort')->label('Order')->numeric()->minValue(0)->maxValue(65535)->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('Name')->searchable()->sortable(),
                TextColumn::make('replies_count')->label('Replies')->counts('replies'),
                TextColumn::make('sort')->label('Order')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription('Its replies are kept, under General.'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCannedReplyCategories::route('/')];
    }
}
