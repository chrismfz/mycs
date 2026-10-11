<?php

namespace App\Admin\Pages;

use App\Admin\Clusters\Extensions;
use App\Admin\Resources\ExtensionResource;
use App\Helpers\ExtensionHelper;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class Extension extends Page implements HasActions, HasTable
{
    use InteractsWithActions, InteractsWithTable;

    protected string $view = 'admin.pages.extension';

    // Cluster
    protected static ?string $cluster = Extensions::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-download-2-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-download-2-fill';

    public function getMaxContentWidth(): Width
    {
        return Width::MaxContent;
    }

    // Label for the navigation item
    protected static ?string $navigationLabel = 'Available Extensions';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => collect(ExtensionHelper::getInstallableExtensions()))
            ->description('Extensions in extensions/ that can be installed (not gateways or servers). New extensions arrive as code, through git and deploy/update.sh: there is no upload (it would run uploaded PHP).')
            ->columns([
                ImageColumn::make('meta.icon')
                    ->label('Icon')
                    ->state(fn ($record) => $record['meta']?->icon ? $record['meta']->icon : 'ri-puzzle-fill'),
                TextColumn::make('meta.name')
                    ->label('Extension Name')
                    ->searchable()
                    ->sortable()
                    ->state(fn ($record) => $record['meta'] ? $record['meta']->name . ' (' . $record['meta']->author . ')' : $record['name']),
                TextColumn::make('meta.description')
                    ->label('Description')
                    ->searchable()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('install')
                    ->label('Install')
                    ->action(function ($record) {
                        $extension = \App\Models\Extension::create([
                            'name' => $record['name'],
                            'type' => $record['type'],
                            'extension' => $record['name'],
                        ]);
                        ExtensionHelper::call($extension, 'installed', mayFail: true);

                        Notification::make()
                            ->title('Extension Installed')
                            ->body('The extension has been successfully installed.')
                            ->success()
                            ->send();

                        $this->redirect(ExtensionResource::getUrl('edit', ['record' => $extension->id]), true);
                    })
                    ->requiresConfirmation(),
            ]);
    }

    public static function canAccess(): bool
    {
        return Auth::user()->hasPermission('admin.extensions.viewAny') && Auth::user()->hasPermission('admin.extensions.install');
    }
}
