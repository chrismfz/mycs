<?php

namespace App\Admin\Pages;

use App\Admin\Clusters\Extensions;
use App\Admin\Resources\ExtensionResource;
use App\Admin\Resources\GatewayResource;
use App\Admin\Resources\ServerResource;
use App\Helpers\ExtensionHelper;
use App\Services\Extensions\UploadExtensionService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\FileUpload;
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
            ->description('List of available extensions (not gateway or server extensions) that can be installed.')
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
            ])
            ->headerActions([
                Action::make('upload')
                    ->label('Upload Extension')
                    ->icon('ri-upload-2-line')
                    ->form([
                        FileUpload::make('file')
                            ->label('Extension File')
                            ->required()
                            ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                            ->directory('extensions/uploaded')
                            ->preserveFilenames()
                            ->maxSize(10240), // 10 MB
                    ])
                    ->action(function (array $data, UploadExtensionService $service) {
                        try {
                            $type = $service->handle(storage_path('app/' . $data['file']));
                            // Handle the exception, e.g., log it or show an error message
                            switch ($type) {
                                case 'server':
                                    Notification::make()
                                        ->title('Extension uploaded successfully')
                                        ->body('Server uploaded successfully. Please go to the <a class="text-primary-600" wire:navigate href="' . ServerResource::getUrl() . '">Servers</a> page to install the new server extension.')
                                        ->success()
                                        ->send();
                                    break;
                                case 'gateway':
                                    Notification::make()
                                        ->title('Extension uploaded successfully')
                                        ->body('Gateway uploaded successfully. Please go to the <a class="text-primary-600" wire:navigate href="' . GatewayResource::getUrl() . '">Gateways</a> page to install the new gateway extension.')
                                        ->success()
                                        ->send();
                                    break;
                                default:
                                    // Unknown type, just stay on the page
                                    Notification::make()
                                        ->title('Extension uploaded successfully')
                                        ->body('It should now be available in the list below.')
                                        ->success()
                                        ->send();
                                    break;
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Failed to upload extension')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function canAccess(): bool
    {
        return Auth::user()->hasPermission('admin.extensions.viewAny') && Auth::user()->hasPermission('admin.extensions.install');
    }
}
