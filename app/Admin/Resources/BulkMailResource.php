<?php

namespace App\Admin\Resources;

use App\Admin\Resources\BulkMailResource\Pages\CreateBulkMail;
use App\Admin\Resources\BulkMailResource\Pages\EditBulkMail;
use App\Admin\Resources\BulkMailResource\Pages\ListBulkMails;
use App\Helpers\TemplateRenderer;
use App\Models\BulkMail;
use App\Models\BulkMailRecipient;
use App\Models\ClientGroup;
use App\Models\DomainTld;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The mass mail (ROADMAP 6δ, WHMCS's Mass Mail Tool): a message to the accounts its filters find, a draft until staff
 * send it (BulkMailer). Its text is a template like the email templates (rendered from data, never compiled).
 */
class BulkMailResource extends Resource
{
    protected static ?string $model = BulkMail::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Other';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-mail-send-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-mail-send-fill';

    public static function getModelLabel(): string
    {
        return __('Mass mail');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Mass mails');
    }

    public static function form(Schema $schema): Schema
    {
        $template = __('Markdown. Values: {{ user.first_name }}, {{ client.name }}; the matching services and domains: {% for service in services %}{{ service.label }}{% endfor %}, {% for domain in domains %}{{ domain.fqdn }}{% endfor %}. No PHP or Blade.');

        return $schema->disabled(fn (?BulkMail $record) => $record !== null && !$record->isDraft())->components([
            Section::make(__('Message'))->columnSpanFull()->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255)->helperText(__('For staff.')),
                Radio::make('kind')->label(__('Kind'))->required()->default(BulkMail::KIND_IMPORTANT)->options([
                    BulkMail::KIND_IMPORTANT => __('Important announcement (a server moving, the network changing): sent to everyone it is for'),
                    BulkMail::KIND_NEWS => __('News and offers: not to those who turned them off, with a link to stop them'),
                ]),
                TextInput::make('subject')->label(__('Subject'))->required()->maxLength(255)->rule(TemplateRenderer::rule()),
                Textarea::make('body')->label(__('Message'))->required()->rows(12)->rule(TemplateRenderer::rule())->helperText($template),
            ]),
            Section::make(__('In Greek'))->columnSpanFull()->collapsible()->description(__('For the accounts that read Greek; without it they get the text above.'))->schema([
                TextInput::make('subject_el')->label(__('Subject'))->maxLength(255)->rule(TemplateRenderer::rule())->requiredWith('body_el'),
                Textarea::make('body_el')->label(__('Message'))->rows(12)->rule(TemplateRenderer::rule())->requiredWith('subject_el'),
            ]),
            Section::make(__('Recipients'))->columnSpanFull()->schema([
                Radio::make('filters.target')->label(__('Who'))->required()->default('all')->live()->options([
                    'all' => __('All customers'),
                    'services' => __('Customers with services that match'),
                    'domains' => __('Customers with domains that match'),
                ]),
                Select::make('filters.client_groups')->label(__('Client groups'))->multiple()->options(fn () => ClientGroup::query()->orderBy('name')->pluck('name', 'id'))
                    ->helperText(__('Only these groups; none: every group.')),
                Toggle::make('filters.active_only')->label(__('Only customers with an active service or domain'))
                    ->visible(fn (Get $get) => $get('filters.target') === 'all'),
                Select::make('filters.service_statuses')->label(__('Service status'))->multiple()->default([Service::STATUS_ACTIVE])
                    ->options(array_combine(Service::STATUSES, array_map('ucfirst', Service::STATUSES)))
                    ->visible(fn (Get $get) => $get('filters.target') === 'services'),
                Select::make('filters.products')->label(__('Products'))->multiple()->searchable()->options(fn () => Product::query()->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn (Get $get) => $get('filters.target') === 'services')->helperText(__('None: any product.')),
                Select::make('filters.servers')->label(__('Servers'))->multiple()->searchable()->options(fn () => Server::query()->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn (Get $get) => $get('filters.target') === 'services')->helperText(__('None: any server.')),
                Select::make('filters.domain_statuses')->label(__('Domain status'))->multiple()->default(['active'])
                    ->options(['active' => 'Active', 'pending_register' => 'Pending registration', 'pending_transfer' => 'Pending transfer', 'expired' => 'Expired', 'grace' => 'Grace', 'redemption' => 'Redemption'])
                    ->visible(fn (Get $get) => $get('filters.target') === 'domains'),
                Select::make('filters.tlds')->label(__('TLDs'))->multiple()->searchable()->options(fn () => DomainTld::query()->orderBy('tld')->pluck('tld', 'tld'))
                    ->visible(fn (Get $get) => $get('filters.target') === 'domains')->helperText(__('None: any TLD.')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('name')->label(__('Name'))->searchable(),
            TextColumn::make('kind')->label(__('Kind'))->badge()->formatStateUsing(fn (string $state) => $state === BulkMail::KIND_NEWS ? __('News') : __('Important')),
            TextColumn::make('status')->label(__('Status'))->badge()
                ->color(fn (string $state) => match ($state) {
                    BulkMail::STATUS_SENT => 'success', BulkMail::STATUS_SENDING => 'warning', default => 'gray'
                }),
            TextColumn::make('recipients_count')->label(__('Recipients')),
            TextColumn::make('sent')->label(__('Sent'))->state(fn (BulkMail $record) => $record->recipients()->where('status', BulkMailRecipient::STATUS_SENT)->count()),
            TextColumn::make('started_at')->label(__('Sent at'))->dateTime('d/m/Y H:i'),
        ])->recordActions([EditAction::make()])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBulkMails::route('/'),
            'create' => CreateBulkMail::route('/create'),
            'edit' => EditBulkMail::route('/{record}/edit'),
        ];
    }
}
