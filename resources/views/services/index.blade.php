<div class="container mt-14 space-y-4">
    <x-navigation.breadcrumb />
    <input type="search" wire:model.live.debounce.400ms="search" maxlength="100" placeholder="{{ __('services.search_placeholder') }}"
        aria-label="{{ __('services.search') }}"
        class="w-full sm:max-w-sm bg-background-secondary border border-neutral rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
    @forelse ($services as $service)
    <a href="{{ route('services.show', $service) }}" wire:navigate>
        <div class="bg-background-secondary hover:bg-background-secondary/80 border border-neutral p-4 rounded-lg mb-4">
        <div class="flex items-start justify-between gap-2 mb-2">
            <div class="flex items-center gap-3 min-w-0">
            <div class="bg-secondary/10 p-2 rounded-lg">
                <x-ri-instance-line class="size-5 text-secondary" />
            </div>
            <span class="min-w-0">
                <span class="block font-medium [overflow-wrap:anywhere]">{{ $service->label }}</span>
                {{-- What tells it apart (ServiceIdentifier, owner 2026-11-03); a license's key and IPs are on the line below. --}}
                {{-- Its IP is on that line too, as «Dedicated IP» (review of #615). --}}
                @php($identifier = $service->license === null ? \App\Support\ServiceIdentifier::besides($service, (string) $service->label, except: [(string) $service->dedicated_ip]) : '')
                @if ($identifier !== '')
                <span class="block text-sm text-base/60 [overflow-wrap:anywhere]">{{ $identifier }}</span>
                @endif
                @if (($service->running_addons_count ?? 0) > 0)
                <span class="block text-sm text-base/60">{{ trans_choice('services.addons.count', $service->running_addons_count, ['count' => $service->running_addons_count]) }}</span>
                @endif
            </span>
            </div>
            {{--
                Its status in words, not only a colour (owner 2026-11-03: suspended and cancelled had the same grey icon). An
                active one being cancelled says so, as its page does (review of #619). The word in the text's own colour, the
                tone on its icon and tint: coloured text on the light theme's grey was hard to read.
            --}}
            @php([$tone, $icon, $word, $ending] = \App\Livewire\Services\Index::status($service))
            <span class="shrink-0 whitespace-nowrap inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold {{ $tone }}">
                <x-dynamic-component :component="$icon" class="size-4" aria-hidden="true" />
                <span class="text-base">{{ $word }}</span>
            </span>
        </div>
        <div class="text-base text-sm flex gap-1">
            {{
                in_array($service->plan->type, ['recurring']) ?  __('services.every_period', [
                'period' => $service->plan->billing_period > 1 ? $service->plan->billing_period : '',
                'unit' => trans_choice(__('services.billing_cycles.' . $service->plan->billing_unit),
                $service->plan->billing_period)
                ]) : '' }}
                {{-- An ended service renews no more, nor one being cancelled (owner 2026-11-03: a cancelled one said «Renews in 4 weeks»). --}}
                @if($service->expires_at && $service->expires_at > now() && !$service->isEnded() && !$ending)
                -  {{ __('services.renews_in') }} 
                <x-tooltip :message="$service->expires_at->format('M d, Y')">
                    {{ $service->expires_at->longAbsoluteDiffForHumans() }}
                </x-tooltip>
                @endif
            </div>
            {{-- What tells it apart: its license key (2026-10-28), its IP and the fields its license reports (owner 2026-10-23). --}}
            @php($glance = \App\Livewire\Services\Index::glance($service))
            @if ($glance['key'] !== null || $glance['ip'] !== null || $glance['fields'] !== [])
            <div class="text-sm text-base/60 flex flex-wrap gap-x-4 gap-y-1 mt-1">
                @if ($glance['key'] !== null)
                {{--
                    Inside the card's link: wire:navigate starts on mousedown (and Enter), so those stop here too (review of
                    #493), and the key can be selected by hand.
                --}}
                <span x-data="{ copied: false }" x-on:mousedown.stop x-on:keydown.enter.stop x-on:click.prevent.stop><span class="text-base/80">{{ __('services.license.key') }}:</span>
                    <code class="font-mono select-all cursor-text">{{ $glance['key'] }}</code>
                    <button type="button" class="ml-1 underline"
                        x-on:click="navigator.clipboard.writeText(@js($glance['key'])); copied = true; setTimeout(() => copied = false, 2000)"
                        x-text="copied ? @js(__('services.license.copied')) : @js(__('services.license.copy'))">{{ __('services.license.copy') }}</button>
                </span>
                @endif
                @if ($glance['ip'] !== null)
                <span><span class="text-base/80">{{ $glance['ip_is_license'] ? __('services.license.valid_ips') : __('services.dedicated_ip') }}:</span> {{ $glance['ip'] }}</span>
                @endif
                @foreach ($glance['fields'] as $name => $value)
                <span><span class="text-base/80">{{ $name }}:</span> {{ \Illuminate\Support\Str::limit($value, 80) }}</span>
                @endforeach
            </div>
            @endif
        </div>
    </a>
    @empty
    <div class="bg-background-secondary border border-neutral p-4 rounded-lg">
        <p class="text-base text-sm">{{ $search !== '' ? __('services.no_match', ['search' => $search]) : __('services.no_services') }}</p>
    </div>
    @endforelse

    {{ $services->links() }}
</div>
