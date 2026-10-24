<?php

namespace App\Admin\Resources\ServiceResource\RelationManagers;

use App\Exceptions\ServerRefused;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Models\SslOrder;
use App\Models\SslVersion;
use App\Models\Staff;
use App\Services\Ssl\SslVault;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use MyCS\SslTools\KeyType;
use Throwable;

/**
 * The SSL vault of an Openprovider SSL service (docs/ssl-vault.md): every key and CSR its order used, the certificate
 * of each, their files; a key generated and ordered (or reissued) for the customer; a key kept elsewhere attached. A
 * private key is seen or downloaded only with its own permission (SslVault::KEY_PERMISSION), and each time audited.
 */
class SslVersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sslVersions';

    protected static ?string $title = 'SSL keys and certificates';

    /** Keys a member of staff may generate an hour. */
    private const GENERATE_PER_HOUR = 30;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Service && $ownerRecord->sslOrder()->exists() && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    private static function staff(): ?Staff
    {
        $staff = Auth::guard('staff')->user();

        return $staff instanceof Staff ? $staff : null;
    }

    private static function mayKey(): bool
    {
        return (bool) self::staff()?->hasPermission(SslVault::KEY_PERMISSION);
    }

    private static function mayUpdate(): bool
    {
        return (bool) self::staff()?->hasPermission('admin.services.update');
    }

    private function service(): Service
    {
        $service = $this->getOwnerRecord();
        abort_unless($service instanceof Service, 404);

        return $service;
    }

    private function order(): SslOrder
    {
        return SslOrder::query()->where('service_id', $this->service()->id)->sole();
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('number', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('number')->label('#'),
                TextColumn::make('state')->label('State')->badge()
                    ->state(fn (SslVersion $record) => match (true) {
                        $record->id === app(SslVault::class)->current($this->order())?->id => 'in use',
                        $record->ordered_at === null => 'not ordered',
                        default => 'replaced',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'in use' => 'success',
                        'not ordered' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('common_name')->label('Names')
                    ->state(fn (SslVersion $record) => implode(', ', $record->host_names ?? array_filter([$record->common_name])))->wrap(),
                TextColumn::make('key_description')->label('Key')->placeholder('—')
                    ->description(fn (SslVersion $record) => $record->hasKey() ? 'kept here' : 'not kept here'),
                TextColumn::make('spki_sha256')->label('Key fingerprint')->placeholder('—')->fontFamily('mono')
                    ->formatStateUsing(fn (?string $state) => $state ? substr($state, 0, 8) . '…' . substr($state, -8) : null)
                    ->copyable()->copyableState(fn (SslVersion $record) => $record->spki_sha256),
                TextColumn::make('source')->label('Made')
                    ->formatStateUsing(fn (string $state) => ['generated' => 'generated here', 'pasted' => 'customer\'s CSR', 'synced' => 'from certificate'][$state] ?? $state)
                    ->description(fn (SslVersion $record) => $record->created_at->toDateString()),
                TextColumn::make('expires_at')->label('Expires')->date()->placeholder('—'),
            ])
            ->headerActions([
                $this->generateAction('sslGenerateOrder', reissue: false),
                $this->generateAction('sslGenerateReissue', reissue: true),
            ])
            ->recordActions([
                ActionGroup::make([
                    ...array_map(fn (string $file) => self::fileAction($file), ['crt', 'ca-bundle', 'fullchain', 'csr']),
                    Action::make('viewPem')->label('View certificate and CSR')->icon('ri-eye-line')
                        ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                        ->modalContent(fn (SslVersion $record) => self::pemView([
                            'Certificate' => $record->certificate, 'CA bundle' => $record->chain, 'CSR' => $record->csr,
                        ])),
                ])->label('Files')->icon('ri-file-download-line')->button()->color('gray'),
                ActionGroup::make([
                    // Opening it shows the key, and the audit is written as it is read.
                    Action::make('revealKey')->label('Reveal key (audited)')->icon('ri-eye-line')->color('warning')
                        ->visible(fn (SslVersion $record) => $record->hasKey() && self::mayKey())
                        ->modalHeading('Private key')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                        ->modalContent(function (SslVersion $record) {
                            abort_unless(self::mayKey(), 403);

                            return self::pemView(['Private key' => app(SslVault::class)->keyPem($record, 'admin')]);
                        }),
                    Action::make('downloadKey')->label('Download .key')->icon('ri-key-2-line')->color('warning')
                        ->visible(fn (SslVersion $record) => $record->hasKey() && self::mayKey())
                        ->action(function (SslVersion $record) {
                            abort_unless(self::mayKey(), 403);
                            $pem = app(SslVault::class)->keyPem($record, 'admin');

                            return response()->streamDownload(function () use ($pem) {
                                echo $pem;
                            }, $record->fileName('key'), ['Content-Type' => 'application/x-pem-file', 'Cache-Control' => 'no-store']);
                        }),
                    Action::make('downloadPfx')->label('Download .pfx…')->icon('ri-file-lock-line')->color('warning')
                        ->visible(fn (SslVersion $record) => $record->hasKey() && filled($record->certificate) && self::mayKey())
                        ->schema([
                            TextInput::make('password')->label('Password for the .pfx')->password()->required()->minLength(8)->maxLength(128)
                                ->helperText('Tell it to the customer apart from the file. It is not kept.'),
                        ])
                        ->action(function (SslVersion $record, array $data, Action $action) {
                            abort_unless(self::mayKey(), 403);
                            try {
                                $pfx = app(SslVault::class)->pfx($record, (string) $data['password'], 'admin');
                            } catch (ServerRefused $e) {
                                Notification::make()->danger()->title('No .pfx')->body($e->getMessage())->send();
                                $action->halt();

                                return null;
                            }

                            return response()->streamDownload(function () use ($pfx) {
                                echo $pfx;
                            }, preg_replace('/\.key$/', '.pfx', $record->fileName('key')), ['Content-Type' => 'application/x-pkcs12', 'Cache-Control' => 'no-store']);
                        }),
                    Action::make('attachKey')->label('Attach existing key…')->icon('ri-key-line')
                        ->visible(fn (SslVersion $record) => !$record->hasKey() && $record->spki_sha256 !== null && self::mayKey() && self::mayUpdate())
                        ->modalDescription('Paste the private key kept elsewhere (an admin note, the customer\'s server). It is kept only if it belongs to this version\'s CSR or certificate.')
                        ->schema([
                            Textarea::make('key')->label('Private key (PEM, no passphrase)')->required()->rows(10)->extraInputAttributes(['class' => 'font-mono text-xs', 'autocomplete' => 'off']),
                        ])
                        ->action(function (SslVersion $record, array $data, Action $action) {
                            abort_unless(self::mayKey() && self::mayUpdate(), 403);
                            try {
                                app(SslVault::class)->attachKey($record, (string) $data['key']);
                            } catch (ServerRefused $e) {
                                Notification::make()->danger()->title('Not attached')->body($e->getMessage())->send();
                                $action->halt();

                                return;
                            }
                            Notification::make()->success()->title('The key was attached')->send();
                        }),
                ])->label('Key')->icon('ri-key-2-line')->button()->color('warning')
                    ->visible(fn (SslVersion $record) => self::mayKey()),
            ]);
    }

    /** A version's public file, downloaded. */
    private static function fileAction(string $file): Action
    {
        $labels = ['crt' => 'Certificate (.crt)', 'ca-bundle' => 'CA bundle', 'fullchain' => 'Full chain', 'csr' => 'CSR'];

        return Action::make("download-{$file}")->label($labels[$file])->icon('ri-download-line')
            ->visible(fn (SslVersion $record) => $record->publicFile($file) !== null)
            ->action(function (SslVersion $record) use ($file) {
                $content = (string) $record->publicFile($file);

                return response()->streamDownload(function () use ($content) {
                    echo $content;
                }, $record->fileName($file), ['Content-Type' => 'application/x-pem-file']);
            });
    }

    /** @param array<string, ?string> $blocks */
    private static function pemView(array $blocks): HtmlString
    {
        $html = '';
        foreach (array_filter($blocks, 'filled') as $label => $pem) {
            $html .= '<p class="text-sm font-medium mt-2">' . e($label) . '</p><textarea readonly rows="10" class="w-full font-mono text-xs rounded-lg border border-gray-300 dark:border-white/10 bg-transparent p-2" onclick="this.select()">'
                . e((string) $pem) . '</textarea>';
        }

        return new HtmlString($html !== '' ? $html : '<p class="text-sm">Nothing kept.</p>');
    }

    /**
     * A key generated and its CSR ordered (a service waiting for its CSR) or reissued (an issued certificate), for the
     * customer: the same calls the client area makes, with the validation chosen here.
     */
    private function generateAction(string $name, bool $reissue): Action
    {
        return Action::make($name)
            ->label($reissue ? 'Reissue with a key from here…' : 'Generate key and order…')->icon('ri-key-2-line')
            ->visible(function () use ($reissue) {
                if (!self::mayUpdate()) {
                    return false;
                }
                $order = $this->order();

                return $reissue
                    ? $order->order_id !== null && $order->issued() && $order->status === 'ACT' && $order->renew_requested_at === null
                        && ($order->order_ends_at === null || $order->order_ends_at->isFuture())
                    : $order->awaitingConfiguration();
            })
            ->fillForm(fn () => ['domain' => $this->order()->common_name, 'key_type' => KeyType::Rsa2048->value, 'key' => 'new', 'method' => 'dns'])
            ->schema([
                TextInput::make('domain')->label('Domain')->required()->maxLength(253)->live(onBlur: true)->readOnly($reissue)
                    ->helperText('example.gr covers it and its www; *.example.gr the wildcard and example.gr.'),
                Select::make('key')->label('Private key')->options(['new' => 'A new key (recommended)', 'keep' => 'Keep the current key'])
                    ->visible($reissue)->required()->live(),
                Select::make('key_type')->label('Key type')->required()
                    ->options([KeyType::Rsa2048->value => 'RSA 2048', KeyType::EcP256->value => 'EC P-256'])
                    ->visible(fn (Get $get) => !$reissue || $get('key') === 'new'),
                Select::make('method')->label('Validation')->required()->live()
                    ->options(['dns' => 'DNS record (CNAME)', 'https' => 'File on the website (HTTPS)', 'email' => 'Email to an approver address']),
                Select::make('approver')->label('Approver address')->required()
                    ->visible(fn (Get $get) => $get('method') === 'email')
                    ->options(function (Get $get) {
                        try {
                            $names = SslVault::namesFor((string) $get('domain'));

                            return collect((array) ExtensionHelper::callService($this->service(), 'approverEmails', [$names[0]]))
                                ->mapWithKeys(fn ($email) => [(string) $email => (string) $email])->all();
                        } catch (Throwable) {
                            return [];
                        }
                    }),
            ])
            ->modalDescription($reissue
                ? 'A new certificate for the same name (not charged), with a key made here. The customer validates it again.'
                : 'Orders the certificate at Openprovider (charged), with a key made here and kept in the vault.')
            ->action(function (array $data, Action $action) use ($reissue) {
                abort_unless(self::mayUpdate(), 403);
                $limit = 'admin-ssl-generate:' . self::staff()?->id;
                if (RateLimiter::tooManyAttempts($limit, self::GENERATE_PER_HOUR)) {
                    Notification::make()->danger()->title('Too many keys generated: try again later')->send();
                    $action->halt();
                }
                RateLimiter::hit($limit, 3600);
                $order = $this->order();
                $vault = app(SslVault::class);
                try {
                    $keep = $reissue && ($data['key'] ?? 'new') === 'keep' ? ($vault->current($order) ?? throw new ServerRefused(__('services.ssl.vault.no_key_to_keep'))) : null;
                    $version = $vault->generate($order, KeyType::from((string) ($data['key_type'] ?? KeyType::Rsa2048->value)), SslVault::namesFor((string) $data['domain']), $keep);
                    $method = (string) $data['method'];
                    $done = ExtensionHelper::callService($this->service(), $reissue ? 'reissue' : 'placeOrder',
                        [(string) $version->csr, $method, $method === 'email' ? (string) ($data['approver'] ?? '') : null]);
                    if ($done !== true) {
                        throw new Exception('Openprovider did not confirm it');
                    }
                } catch (ServerRefused $e) {
                    Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title('Not confirmed')->body('Openprovider did not answer clearly: nothing is ordered twice. Check the order shortly.')->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title($reissue ? 'Reissue requested' : 'Certificate ordered')->send();
            });
    }
}
