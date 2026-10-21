<?php

namespace App\Admin\Widgets;

use App\Admin\Resources\DomainRegistrarConnectionResource;
use App\Models\DomainRegistrarConnection;
use App\Services\Domains\RegistrarBalances;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * «Registrar credit» (G8-1, owner 2026-10-19, as WHMCS shows it): what is left with each registrar, as last read by
 * `domains:balances` (hourly); a page never calls a registrar. Red when staff are told about it (low, suspended, unread).
 * One thin line under the cards (owner 2026-10-21: the full-width cards with an empty third «sucked», «tiny»).
 */
class RegistrarCredit extends Widget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'admin.widgets.registrar-credit';

    public function render(): View
    {
        $rows = app(RegistrarBalances::class)->connections()->map(function (DomainRegistrarConnection $connection) {
            $balance = $connection->balance ?? [];
            $amount = is_string($balance['amount'] ?? null) ? number_format((float) $balance['amount'], 2, ',', '.') . ' ' . match ($balance['currency'] ?? null) {
                'EUR' => '€',
                null => '',
                default => $balance['currency'],
            } : '—';
            $reserved = is_string($balance['reserved'] ?? null) && (float) $balance['reserved'] !== 0.0 ? __('reserved :amount', ['amount' => $balance['reserved']]) : null;

            return [
                'label' => $connection->label,
                'amount' => trim($amount),
                'problem' => RegistrarBalances::problemText($connection),
                'read' => is_string($balance['read_at'] ?? null) ? __('Last read :when', ['when' => Carbon::parse($balance['read_at'])->diffForHumans()]) : __('Not read yet'),
                'reserved' => $reserved,
            ];
        })->values()->all();

        return view($this->view, [
            'rows' => $rows,
            'url' => DomainRegistrarConnectionResource::canViewAny() ? DomainRegistrarConnectionResource::getUrl('index') : null,
        ]);
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.widgets.registrar_credit') && app(RegistrarBalances::class)->connections()->isNotEmpty();
    }
}
