<x-filament-widgets::widget>
    {{-- Inline styles for the grid: the theme's CSS has only the classes it was built with. --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('Cron by day') }} · {{ $monthLabel }}</x-slot>
        <x-slot name="afterHeader">
            <div style="display:flex;gap:.25rem">
                <x-filament::icon-button icon="heroicon-m-chevron-left" wire:click="previousMonth" :label="__('Previous month')" />
                <x-filament::icon-button icon="heroicon-m-chevron-right" wire:click="nextMonth" :label="__('Next month')" />
            </div>
        </x-slot>

        @if (!$suspendOn || !$terminateOn)
        <div class="mb-3 rounded-lg px-3 py-2 text-sm" style="background: color-mix(in oklab, var(--warning-500) 15%, transparent)">
            {{ __('Safety net on (Settings → Cron): the cron does not :what. What it would do is listed, for staff to do by hand or leave; switched back on, its first run does all of it.', ['what' => !$suspendOn && !$terminateOn ? __('suspend or terminate') : (!$suspendOn ? __('suspend') : __('terminate'))]) }}
        </div>
        @endif

        <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px" class="text-xs text-gray-500 dark:text-gray-400">
            @foreach ([__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')] as $weekday)
            <div class="px-1">{{ $weekday }}</div>
            @endforeach
        </div>
        @foreach ($weeks as $week)
        <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;margin-top:4px">
            @foreach ($week as $d)
            @php($c = ($d['cell']['ran'] ?? false) ? $d['cell'] : null)
            @php($flag = $d['cell']['attention'] ?? false)
            @php($f = $d['forecast'])
            <button type="button" wire:click="pick('{{ $d['date'] }}')"
                class="rounded-lg p-1.5 text-start text-xs ring-1 ring-gray-950/5 dark:ring-white/10 {{ $d['in_month'] ? '' : 'opacity-50' }}"
                style="min-height:4.25rem;{{ $d['date'] === $selected ? 'outline:2px solid var(--primary-500);' : '' }}{{ $d['future'] ? 'border:1px dashed color-mix(in oklab, currentColor 30%, transparent);' : '' }}{{ $d['past'] && $c === null ? 'background: color-mix(in oklab, currentColor 6%, transparent);' : '' }}">
                <div style="display:flex;justify-content:space-between" class="font-medium text-gray-900 dark:text-white">
                    <span>{{ $d['number'] }}</span>
                    @if ($flag)<span style="width:.5rem;height:.5rem;border-radius:9999px;background:var(--danger-500)" title="{{ __('Something failed or waits for staff') }}"></span>@endif
                </div>
                @if ($c !== null)
                <div>{{ trans_choice(':count invoice|:count invoices', $c['invoices']) }}</div>
                <div class="text-gray-500 dark:text-gray-400">{{ __(':ok/:declined cards', ['ok' => $c['charged'], 'declined' => $c['declined']]) }}</div>
                @if ($c['suspended'] + $c['terminated'] > 0)<div class="text-gray-500 dark:text-gray-400">{{ __(':s susp. · :t term.', ['s' => $c['suspended'], 't' => $c['terminated']]) }}</div>@endif
                @elseif ($f !== null)
                @if ($f['suspend'] !== [])<div style="color:var(--warning-600)">{{ __(':count to suspend', ['count' => count($f['suspend'])]) }}</div>@endif
                @if ($f['terminate'] !== [])<div style="color:var(--danger-600)">{{ __(':count to terminate', ['count' => count($f['terminate'])]) }}</div>@endif
                @elseif ($d['past'])
                <div class="text-gray-400">{{ __('no run') }}</div>
                @endif
            </button>
            @endforeach
        </div>
        @endforeach
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Dashed: the days to come, what the cron will suspend and terminate if nothing is paid. Grey: the cron did not run.') }}</p>
    </x-filament::section>

    <x-filament::section class="mt-4">
        <x-slot name="heading">{{ $selectedLabel }}</x-slot>

        @if ($isToday && ($now['suspend']->isNotEmpty() || $now['terminate']->isNotEmpty()))
        <h4 class="mb-1 text-sm font-medium" style="color:var(--warning-600)">{{ $suspendOn ? __('Due now: the cron suspends these at its next run') : __('Waiting to suspend: held by the safety net') }}</h4>
        @include('admin.widgets.partials.cron-services', ['services' => $now['suspend']])
        @if ($now['terminate']->isNotEmpty())
        <h4 class="mb-1 mt-3 text-sm font-medium" style="color:var(--danger-600)">{{ $terminateOn ? __('Due now: the cron terminates these at its next run') : __('Waiting to terminate: held by the safety net') }}</h4>
        @include('admin.widgets.partials.cron-services', ['services' => $now['terminate']])
        @endif
        @endif

        @if ($isFuture)
        <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">{{ __('If nothing is paid, and no hold or date is set before then.') }}</p>
        <h4 class="mb-1 text-sm font-medium" style="color:var(--warning-600)">{{ __(':count to suspend', ['count' => $upcoming['suspend']->count()]) }}</h4>
        @include('admin.widgets.partials.cron-services', ['services' => $upcoming['suspend']])
        <h4 class="mb-1 mt-3 text-sm font-medium" style="color:var(--danger-600)">{{ __(':count to terminate', ['count' => $upcoming['terminate']->count()]) }}</h4>
        @include('admin.widgets.partials.cron-services', ['services' => $upcoming['terminate']])
        @else
        @php($stats = $detail['stats'])
        @if ($stats === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('The cron did not run this day.') }}</p>
        @endif

        <h4 class="mb-1 mt-3 text-sm font-medium">{{ __('Invoices issued') }} · {{ $detail['invoices']['count'] }}</h4>
        <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
            @foreach ($detail['invoices']['rows'] as $invoice)
            <li class="flex justify-between gap-3 py-1">
                <a @if ($u = $invoiceUrl($invoice->id)) href="{{ $u }}" @endif class="hover:underline">#{{ $invoice->number ?? $invoice->id }} · {{ $invoice->client?->name }}</a>
                <span class="tabular-nums">{{ $invoice->formattedTotal }}</span>
            </li>
            @endforeach
        </ul>
        @if ($detail['invoices']['count'] > count($detail['invoices']['rows']))<p class="text-xs text-gray-500">{{ __('and :count more', ['count' => $detail['invoices']['count'] - count($detail['invoices']['rows'])]) }}</p>@endif

        <h4 class="mb-1 mt-3 text-sm font-medium">{{ __('Charges of saved methods') }} · {{ $detail['charges']['count'] }}</h4>
        <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
            @foreach ($detail['charges']['rows'] as $charge)
            <li class="flex justify-between gap-3 py-1">
                <a @if ($u = $invoiceUrl($charge->invoice_id)) href="{{ $u }}" @endif class="hover:underline">#{{ $charge->invoice?->number ?? $charge->invoice_id }} · {{ $charge->invoice?->client?->name }}</a>
                <span class="tabular-nums" style="color: {{ $charge->status === 'succeeded' || $charge->status === 'settled' ? 'var(--success-600)' : 'var(--danger-600)' }}">{{ $charge->amount }} {{ $charge->currency_code }} · {{ $charge->status }}@if ($charge->error): {{ \Illuminate\Support\Str::limit((string) $charge->error, 80) }}@endif</span>
            </li>
            @endforeach
        </ul>

        <h4 class="mb-1 mt-3 text-sm font-medium">{{ __('Payments recorded') }} · {{ $detail['payments']['count'] }}</h4>
        <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
            @foreach ($detail['payments']['rows'] as $payment)
            <li class="flex justify-between gap-3 py-1">
                <a @if ($u = $invoiceUrl($payment->invoice_id)) href="{{ $u }}" @endif class="hover:underline">#{{ $payment->invoice?->number ?? $payment->invoice_id }} · {{ $payment->invoice?->client?->name }}</a>
                <span class="tabular-nums">{{ $payment->amount }} · {{ $payment->gateway?->name ?? __('manual') }}</span>
            </li>
            @endforeach
        </ul>

        <h4 class="mb-1 mt-3 text-sm font-medium">{{ __('Suspended') }} · {{ $detail['suspended']['count'] }}</h4>
        @include('admin.widgets.partials.cron-services', ['services' => $detail['suspended']['rows']])
        <h4 class="mb-1 mt-3 text-sm font-medium">{{ __('Terminated') }} · {{ $detail['terminated']['count'] }}</h4>
        @include('admin.widgets.partials.cron-services', ['services' => $detail['terminated']['rows']])

        <h4 class="mb-1 mt-3 text-sm font-medium">{{ __('Module actions') }} · {{ $detail['actions']['count'] }}</h4>
        <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
            @foreach ($detail['actions']['rows'] as $action)
            <li class="flex justify-between gap-3 py-1">
                <span>{{ $action->action }} · {{ $action->service?->label ?? $action->domain?->fqdn }}</span>
                <span style="color: {{ $action->status === 'open' ? 'var(--danger-600)' : 'inherit' }}">{{ $action->status }}</span>
            </li>
            @endforeach
        </ul>

        <h4 class="mb-1 mt-3 text-sm font-medium">{{ __('Domains renewed at the registrar') }} · {{ $stats['domains_renewed'] ?? 0 }}</h4>
        <h4 class="mb-1 mt-3 text-sm font-medium">{{ __('Mails') }} · {{ array_sum($detail['mails']) }}</h4>
        <p class="text-sm text-gray-500 dark:text-gray-400">@foreach ($detail['mails'] as $template => $count){{ $template }} {{ $count }}@if (!$loop->last) · @endif @endforeach</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
