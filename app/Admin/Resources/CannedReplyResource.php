<?php

namespace App\Admin\Resources;

use App\Admin\Resources\CannedReplyResource\Pages\ManageCannedReplies;
use App\Models\CannedReply;
use App\Models\Ticket;
use App\Support\Tickets\CannedReplyExpander;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Predefined replies staff put into a ticket's answer, in categories (docs/tickets.md §6ι). */
class CannedReplyResource extends Resource
{
    protected static ?string $model = CannedReply::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    /** Under Tickets for staff who see them; else on its own (Filament drops a child whose parent is not there). */
    public static function getNavigationParentItem(): ?string
    {
        return auth('staff')->user()?->can('viewAny', Ticket::class) ? 'Tickets' : null;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'ri-chat-quote-line';

    protected static ?string $navigationLabel = 'Predefined Replies';

    protected static ?string $modelLabel = 'Predefined Reply';

    protected static ?string $pluralModelLabel = 'Predefined Replies';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->label('Title')->required()->maxLength(255),
            Select::make('canned_reply_category_id')->label('Category')
                ->relationship('category', 'name', fn ($query) => $query->orderBy('sort')->orderBy('name'))
                ->createOptionForm([TextInput::make('name')->label('Name')->required()->maxLength(255)])
                ->placeholder(__('General')),
            MarkdownEditor::make('body')->label('Reply')->required()->columnSpanFull()
                ->helperText('Filled in when inserted: ' . implode(' · ', CannedReplyExpander::tokens())),
            TextInput::make('sort')->label('Order')->numeric()->minValue(0)->maxValue(65535)->default(0),
            Toggle::make('is_active')->label('Active')->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('title')->label('Title')->searchable()->sortable(),
                TextColumn::make('category.name')->label('Category')->placeholder(__('General'))->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean()->falseIcon('heroicon-o-minus')->falseColor('gray'),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('canned_reply_category_id')->label('Category')->relationship('category', 'name')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCannedReplies::route('/')];
    }
}
