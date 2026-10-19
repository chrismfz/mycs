<div class="container mt-14 space-y-4">
    <x-navigation.breadcrumb :trail="[['name' => __('navigation.domains')]]" />

    <div class="flex flex-col md:flex-row gap-4">
        {{-- Filters, as WHMCS's «My Domains» (D7-2) --}}
        <div class="md:w-56 shrink-0">
            <div class="bg-background-secondary border border-neutral rounded-lg p-3 space-y-1">
                <p class="text-sm font-semibold mb-2">{{ __('domains.portal.show') }}</p>
                @foreach ($counts as $name => $count)
                <button type="button" wire:click="filter('{{ $name }}')"
                    class="w-full flex justify-between items-center px-2 py-1 rounded-md text-sm {{ $status === $name ? 'bg-primary/20 text-primary' : 'hover:bg-background-secondary/80' }}">
                    <span>{{ __('domains.portal.filter.' . $name) }}</span>
                    <span class="text-base/50">{{ $count }}</span>
                </button>
                @endforeach
            </div>
        </div>

        <div class="flex-grow space-y-3">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('domains.portal.search') }}"
                class="w-full md:w-72 bg-background-secondary border border-neutral rounded-lg px-3 py-2 text-sm" />

            <div class="bg-background-secondary border border-neutral rounded-lg overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-base/60 border-b border-neutral">
                        <tr>
                            <th class="px-4 py-3">{{ __('domains.domain') }}</th>
                            <th class="px-4 py-3 hidden md:table-cell">{{ __('domains.portal.registered') }}</th>
                            <th class="px-4 py-3">{{ __('domains.portal.expires') }}</th>
                            <th class="px-4 py-3">{{ __('domains.portal.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($domains as $domain)
                        <tr class="border-b border-neutral last:border-0 hover:bg-background-secondary/80" wire:key="domain-{{ $domain->id }}">
                            <td class="px-4 py-3">
                                <a href="{{ route('domains.show', $domain) }}" wire:navigate class="font-medium text-primary hover:underline">{{ $domain->fqdn }}</a>
                                <div class="text-xs text-base/60 flex items-center gap-1 mt-0.5">
                                    @if (in_array($domain->id, $toggleable, true))
                                    {{-- Auto renew from the row (P4b), confirmed as on its page --}}
                                    <button type="button" role="switch" aria-checked="{{ $domain->auto_renew ? 'true' : 'false' }}"
                                        wire:click="toggleAutoRenew({{ $domain->id }})" wire:loading.attr="disabled" wire:target="toggleAutoRenew({{ $domain->id }})"
                                        wire:confirm="{{ $domain->auto_renew ? __('domains.portal.auto_renew_off_confirm') : __('domains.portal.auto_renew_on_confirm') }}"
                                        class="flex items-center gap-1.5 hover:text-base">
                                        <span class="relative inline-flex h-4 w-7 shrink-0 rounded-full transition {{ $domain->auto_renew ? 'bg-success' : 'bg-neutral' }}">
                                            <span class="absolute top-0.5 size-3 rounded-full bg-white transition-all {{ $domain->auto_renew ? 'left-3.5' : 'left-0.5' }}"></span>
                                        </span>
                                        {{ $domain->auto_renew ? __('domains.portal.auto_renew_on') : __('domains.portal.auto_renew_off') }}
                                    </button>
                                    @elseif ($domain->auto_renew)
                                    <x-ri-check-line class="size-3 text-success" /> {{ __('domains.portal.auto_renew_on') }}
                                    @else
                                    <x-ri-close-line class="size-3 text-inactive" /> {{ __('domains.portal.auto_renew_off') }}
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell">{{ $domain->registered_at?->format('d/m/Y') ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $domain->expires_at?->format('d/m/Y') ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded-md text-xs font-medium
                                    @if ($domain->status === \App\Enums\DomainStatus::Active) text-success bg-success/20
                                    @elseif ($domain->status->isTerminal()) text-inactive bg-inactive/20
                                    @else text-warning bg-warning/20 @endif">{{ $domain->status->getLabel() }}</span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-base/60">{{ __('domains.portal.none') }}</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $domains->links() }}
        </div>
    </div>
</div>
