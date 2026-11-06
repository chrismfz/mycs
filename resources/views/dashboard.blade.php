<div class="container mt-14">
    <x-navigation.breadcrumb />
    <p class="text-base text-base/60 font mt-2 mb-8">
        {{ __('dashboard.dashboard_description') }}
    </p>

    {{-- The account at a glance, each a link to its list (home PR 2, owner 2026-11-03: as WHMCS's) --}}
    @if ($tiles !== [])
    <section aria-labelledby="summary-title">
        <h2 id="summary-title" class="sr-only">{{ __('dashboard.summary.title') }}</h2>
        <ul class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @foreach ($tiles as $tile)
            <li class="min-w-0" wire:key="tile-{{ $tile['key'] }}">
                <a href="{{ $tile['url'] }}" wire:navigate
                    class="block h-full bg-background-secondary hover:bg-background-secondary/80 border border-neutral rounded-lg px-4 py-3 border-b-4 {{ match ($tile['tone']) { 'error' => 'border-b-error', 'warning' => 'border-b-warning', default => 'border-b-primary' } }}">
                    <span class="block text-3xl font-bold leading-tight {{ $tile['tone'] === 'error' ? 'text-error' : 'text-primary' }}">{{ $tile['count'] }}</span>
                    <span class="block text-xs font-semibold uppercase tracking-wide text-base/70">{{ $tile['label'] }}</span>
                    @if ($tile['detail'] !== '')
                    <span class="block text-xs text-base/60 [overflow-wrap:anywhere]">{{ $tile['detail'] }}</span>
                    @endif
                </a>
            </li>
            @endforeach
        </ul>
    </section>
    @endif

    {{-- What waits on the customer, in one place (P4b) --}}
    <section aria-labelledby="attention-title" class="bg-background-secondary border border-neutral rounded-lg p-4 mt-4">
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
                <summary class="list-none [&::-webkit-details-marker]:hidden flex items-start justify-between gap-2 {{ count($month['items']) > 0 ? 'cursor-pointer' : '' }}">
                    <span class="min-w-0">
                        <span class="flex items-center gap-1 font-semibold">
                            {{ $month['label'] }}
                            @if (count($month['items']) > 0)
                            <x-ri-arrow-down-s-line class="size-4 transition group-open:rotate-180" aria-hidden="true" />
                            @endif
                        </span>
                        <span class="block text-sm text-base/60">{{ trans_choice('dashboard.renewals.count', count($month['items']), ['count' => count($month['items'])]) }}</span>
                    </span>
                    <span class="font-semibold text-right">{{ $month['totals'] !== '' ? $month['totals'] : '—' }}</span>
                </summary>
                @if (count($month['items']) > 0)
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($month['items'] as $item)
                    <li class="flex justify-between gap-2">
                        <a href="{{ $item['url'] }}" wire:navigate class="min-w-0 truncate hover:underline" title="{{ $item['name'] }}">{{ $item['date'] }} · {{ $item['name'] }}</a>
                        <span class="whitespace-nowrap text-base/70">
                            @if ($item['invoiced'])
                            <span class="text-xs text-warning">{{ __('dashboard.renewals.invoiced') }}</span>
                            @endif
                            {{ $item['amount'] ?? __('dashboard.renewals.priced_by_us') }}
                        </span>
                    </li>
                    @endforeach
                </ul>
                @endif
            </details>
            @endforeach
        </div>
    </section>
    @endif

    {{-- The services that run, with what tells each apart (ServiceIdentifier) and its page (home PR 2) --}}
    @if(\App\Support\CurrentClient::allows('products'))
    <section aria-labelledby="services-title" class="bg-background-secondary border border-neutral rounded-lg mt-8 overflow-hidden">
        <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-neutral">
            <h2 id="services-title" class="text-lg font-semibold flex items-center gap-2">
                <x-ri-archive-stack-fill class="size-5" />
                {{ __('dashboard.summary.services_table') }}
            </h2>
            <a href="{{ route('services') }}" wire:navigate class="text-sm font-semibold text-primary hover:underline whitespace-nowrap">{{ __('dashboard.summary.all_services') }}</a>
        </div>
        @forelse ($services as $service)
        @php($identifier = \App\Support\ServiceIdentifier::besides($service, (string) $service->label))
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 sm:gap-4 px-4 py-3 border-b border-neutral last:border-b-0" wire:key="home-service-{{ $service->id }}">
            <div class="flex items-start gap-3 min-w-0">
                <span class="shrink-0 mt-0.5 px-2 py-0.5 rounded-full text-xs font-semibold {{ $service->status === 'active' ? 'bg-success/20 text-success' : 'bg-warning/20 text-warning' }}">{{ __('services.statuses.' . $service->status) }}</span>
                <div class="min-w-0">
                    <a href="{{ route('services.show', $service) }}" wire:navigate class="font-semibold hover:underline [overflow-wrap:anywhere]">{{ $service->label }}</a>
                    @if ($identifier !== '')
                    <div class="text-sm text-base/60 [overflow-wrap:anywhere]">{{ $identifier }}</div>
                    @endif
                    @if ($service->expires_at)
                    <div class="text-xs text-base/60">{{ __('dashboard.summary.renews', ['date' => $service->expires_at->format('d/m/Y')]) }}</div>
                    @endif
                </div>
            </div>
            {{-- The same page as its name's link: one tab stop a row (review of #616). --}}
            <a href="{{ route('services.show', $service) }}" wire:navigate tabindex="-1" aria-hidden="true"
                class="shrink-0 self-start sm:self-auto min-h-[36px] inline-flex items-center px-3 rounded-md border border-neutral bg-background text-sm font-semibold hover:bg-background-secondary">{{ __('dashboard.summary.manage') }}</a>
        </div>
        @empty
        <p class="px-4 py-3 text-sm text-base/70">{{ __('dashboard.summary.no_services') }}</p>
        @endforelse
        @if ($servicesCount > $services->count())
        <a href="{{ route('services') }}" wire:navigate class="block px-4 py-2 text-sm text-base/60 hover:underline border-t border-neutral">{{ trans_choice('dashboard.summary.more_services', $servicesCount - $services->count(), ['count' => $servicesCount - $services->count()]) }}</a>
        @endif
    </section>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-12 mt-8 items-start">

        <div class="grid gap-8 items-start">
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
