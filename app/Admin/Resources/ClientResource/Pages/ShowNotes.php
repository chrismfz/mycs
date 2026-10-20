<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Resources\ClientResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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

    private function mayWrite(): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.clients.update');
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
                TextColumn::make('updated_at')->label(__('Modified'))->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()->label(__('Add Note'))->visible(fn () => $this->mayWrite())
                    ->mutateDataUsing(fn (array $data) => [...$data, 'staff_id' => auth()->id()]),
            ])
            ->recordActions([
                EditAction::make()->visible(fn () => $this->mayWrite()),
                DeleteAction::make()->visible(fn () => $this->mayWrite()),
            ])
            ->emptyStateHeading(__('No notes'));
    }
}
