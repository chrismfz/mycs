<div class="container mt-14">
    <x-navigation.breadcrumb />
    <p class="text-base text-base/60 font mt-2 mb-8">
        {{ __('dashboard.dashboard_description') }}
    </p>

    {{-- What waits on the customer, in one place (P4b) --}}
    <section aria-labelledby="attention-title" class="bg-background-secondary border border-neutral rounded-lg p-4 mt-8">
        <h2 id="attention-title" class="text-lg font-semibold mb-3 flex items-center gap-2">
            <x-ri-notification-badge-line class="size-5" />
            {{ __('dashboard.attention.title') }}
        </h2>
        @forelse ($attention as $item)
        <div class="flex flex-wrap items-center justify-between gap-3 py-3 border-t border-neutral first-of-type:border-0" wire:key="attention-{{ $loop->index }}">
            <div class="flex items-start gap-3 min-w-0">
                <span class="mt-0.5 size-6 shrink-0 rounded-md p-1 {{ $item['urgent'] ? 'text-error bg-error/20' : 'text-warning bg-warning/20' }}">
                    @switch($item['kind'])
                        @case('invoices') <x-ri-bill-line /> @break
                        @case('card') <x-ri-bank-card-line /> @break
                        @case('domain') <x-ri-global-line /> @break
                        @default <x-ri-chat-smile-2-line />
                    @endswitch
                </span>
                <div class="min-w-0">
                    <p class="font-medium">{{ $item['text'] }}</p>
                    @if ($item['detail'] !== '')
                    <p class="text-sm text-base/60">{{ $item['detail'] }}</p>
                    @endif
                </div>
            </div>
            <a href="{{ $item['url'] }}" wire:navigate class="shrink-0 bg-primary text-white hover:bg-primary/90 rounded-lg px-4 py-2 text-sm font-semibold">
                {{ $item['action'] }}
            </a>
        </div>
        @empty
        <p class="text-sm text-base/70 flex items-center gap-2">
            <x-ri-checkbox-circle-fill class="size-5 text-success" />
            {{ __('dashboard.attention.all_good') }}
        </p>
        @endforelse
    </section>

    {{-- What renews in the next months and what it costs (P4d) --}}
    @if (\App\Support\CurrentClient::allows('products') || \App\Support\CurrentClient::allows('domains'))
    <section aria-labelledby="renewals-title" class="bg-background-secondary border border-neutral rounded-lg p-4 mt-4">
        <h2 id="renewals-title" class="text-lg font-semibold flex items-center gap-2">
            <x-ri-calendar-schedule-line class="size-5" />
            {{ __('dashboard.renewals.title') }}
        </h2>
        <p class="text-sm text-base/60 mb-3">{{ __('dashboard.renewals.hint') }}</p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            @foreach ($renewals as $month)
            <details class="group bg-background border border-neutral rounded-lg p-3" wire:key="renewals-{{ $month['month'] }}" @if ($loop->first && count($month['items']) > 0) open @endif>
                <summary class="cursor-pointer list-none flex items-start justify-between gap-2">
                    <span>
                        <span class="block font-semibold">{{ $month['label'] }}</span>
                        <span class="block text-sm text-base/60">{{ trans_choice('dashboard.renewals.count', count($month['items']), ['count' => count($month['items'])]) }}</span>
                    </span>
                    <span class="font-semibold whitespace-nowrap">{{ $month['totals'] !== '' ? $month['totals'] : '—' }}</span>
                </summary>
                @if (count($month['items']) > 0)
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($month['items'] as $item)
                    <li class="flex justify-between gap-2">
                        <a href="{{ $item['url'] }}" wire:navigate class="truncate hover:underline" title="{{ $item['name'] }}">{{ $item['date'] }} · {{ $item['name'] }}</a>
                        <span class="whitespace-nowrap text-base/70">{{ $item['amount'] ?? __('dashboard.renewals.priced_by_us') }}</span>
                    </li>
                    @endforeach
                </ul>
                @endif
            </details>
            @endforeach
        </div>
    </section>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-12 mt-8 items-start">

        <div class="grid gap-8 items-start">
            <!-- Active Services -->
            @if(\App\Support\CurrentClient::allows('products'))
            <div class="">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-3">
                        <div class="bg-background-secondary border border-neutral p-2 rounded-lg">
                            <x-ri-archive-stack-fill class="size-5" />
                        </div>
                        <h2 class="text-xl font-semibold">{{ __('dashboard.active_services') }}</h2>
                    </div>
                    <span class="bg-primary flex items-center justify-center font-semibold rounded-md size-5 text-sm text-white">
                        {{ \App\Support\CurrentClient::model()->services()->where('status', 'active')->count() }}
                    </span>
                </div>
                <div class="space-y-4">
                    <livewire:services.widget status="active" />
                </div>
                <x-navigation.link class="bg-background-secondary hover:bg-background-secondary/80 border border-neutral flex items-center justify-center rounded-lg"
                    :href="route('services')">
                    {{ __('dashboard.view_all') }}
                    <x-ri-arrow-right-fill class="size-5" />
                </x-navigation.link>
            </div>
            @endif

            <!-- Open Tickets -->
            @if(!config('settings.tickets_disabled', false) && \App\Support\CurrentClient::allows('tickets'))
            <div class="">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-3">
                        <div class="bg-background-secondary border border-neutral p-2 rounded-lg">
                            <x-ri-customer-service-fill class="size-5" />

                        </div>
                        <h2 class="text-xl font-semibold">{{ __('dashboard.open_tickets') }}</h2>
                        <a href="{{ route('tickets.create') }}" wire:navigate>
                            <x-ri-add-fill class="size-5 h-5" />
                        </a>
                    </div>
                    <span class="bg-primary flex items-center justify-center font-semibold rounded-md size-5 text-sm text-white">
                        {{ \App\Support\CurrentClient::model()->tickets()->where('status', '!=', 'closed')->count() }}
                    </span>
                </div>
                <div class="space-y-4">
                    <livewire:tickets.widget />
                </div>
                <x-navigation.link class="bg-background-secondary hover:bg-background-secondary/80 border border-neutral flex items-center justify-center rounded-lg"
                    :href="route('tickets')">
                    {{ __('dashboard.view_all') }}
                    <x-ri-arrow-right-fill class="size-5 h-5" />
                </x-navigation.link>
            </div>
            @endif
        </div>

        <div class="grid gap-8 items-start">
            <!-- Unpaid Invoices -->
            @if(\App\Support\CurrentClient::allows('invoices'))
            <div class="">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-3">
                        <div class="bg-background-secondary border border-neutral p-2 rounded-lg">
                            <x-ri-receipt-fill class="size-5" />
                        </div>
                        <h2 class="text-xl font-semibold">{{ __('dashboard.unpaid_invoices') }}</h2>
                    </div>
                    <span class="bg-primary flex items-center justify-center font-semibold rounded-md size-5 text-sm text-white">
                        {{ \App\Support\CurrentClient::model()->invoices()->where('status', 'unpaid')->count() }}
                    </span>
                </div>
                <div class="space-y-4">
                    <livewire:invoices.widget :limit="3" />
                </div>
                <x-navigation.link class="bg-background-secondary hover:bg-background-secondary/80 border border-neutral flex items-center justify-center rounded-lg"
                    :href="route('invoices')">
                    {{ __('dashboard.view_all') }}
                    <x-ri-arrow-right-fill class="size-5 h-5" />
                </x-navigation.link>
            </div>
            @endif
            {!! hook('pages.dashboard') !!}
        </div>
    </div>
</div>
