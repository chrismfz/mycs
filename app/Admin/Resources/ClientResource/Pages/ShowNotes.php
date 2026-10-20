<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Resources\ClientResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The account's notes, staff only (WHMCS's client «Notes», ROADMAP «Επόμενα» 2): who wrote each and when, the sticky ones
 * first and on the Summary. Seen by who may see the client; written, changed and removed by who may update it.
 */
class ShowNotes extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'notes';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    public static function getNavigationLabel(): string
    {
        return __('Notes');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')->label(__('Note'))->required()->maxLength(10000)->rows(5)->columnSpanFull(),
            Toggle::make('sticky')->label(__('Sticky'))->helperText(__('Shown at the top of the client\'s Summary.')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->defaultSort(fn ($query) => $query->orderByDesc('sticky')->orderByDesc('created_at')->orderByDesc('id'))
            ->columns([
                IconColumn::make('sticky')->label(__('Sticky'))->icon(fn (bool $state): ?string => $state ? 'heroicon-s-bookmark' : null)
                    ->color('warning')->width('1%'),
                TextColumn::make('body')->label(__('Note'))->wrap()->formatStateUsing(fn (string $state) => nl2br(e($state)))->html(),
                TextColumn::make('staff.name')->label(__('Author'))->placeholder('—'),
                TextColumn::make('created_at')->label(__('Created'))->dateTime(),
                // Changed since (perhaps by someone else: the audit says who): shown, the author stays the writer's.
                TextColumn::make('updated_at')->label(__('Modified'))->dateTime()
                    ->formatStateUsing(fn ($state, $record) => $record->updated_at?->gt($record->created_at) ? $record->updated_at->format('d/m/Y H:i') : '—'),
            ])
            ->headerActions([
                // Authorized by ClientNotePolicy (admin.clients.update); the author is the staff member, by the panel's guard.
                CreateAction::make()->label(__('Add Note'))
                    ->mutateDataUsing(fn (array $data) => [...$data, 'staff_id' => Filament::auth()->id()]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No notes'));
    }
}
