<div class="container mt-14 space-y-4">
    <x-navigation.breadcrumb />

    @if ($openInvoice)
    <div class="bg-yellow-600/20 border-l-4 border-yellow-500 text-yellow-300 p-4 rounded-lg">
        ⚠️ {{ __('domains.portal.open_invoice') }}
        <a href="{{ route('invoices.show', $openInvoice) }}" wire:navigate class="underline underline-offset-2">{{ __('services.view_and_pay') }}</a>.
    </div>
    @endif

    <div class="bg-background-secondary border border-neutral p-6 rounded-lg">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ $domain->fqdn }}</h1>
                <span class="inline-block mt-1 px-2 py-0.5 rounded-md text-xs font-medium
                    @if ($domain->status === \App\Enums\DomainStatus::Active) text-success bg-success/20
                    @elseif ($domain->status->isTerminal()) text-inactive bg-inactive/20
                    @else text-warning bg-warning/20 @endif">{{ $domain->status->getLabel() }}</span>
            </div>
            @if ($renewable)
            @if ($openInvoice)
            <x-button.primary class="!w-fit" wire:click="renew" wire:loading.attr="disabled">{{ __('domains.portal.pay_renewal') }}</x-button.primary>
            @else
            <x-button.primary class="!w-fit" wire:click="renew" wire:loading.attr="disabled" wire:confirm="{{ __('domains.portal.renew_confirm') }}">{{ __('domains.portal.renew') }}</x-button.primary>
            @endif
            @endif
        </div>

        <div class="grid md:grid-cols-2 gap-6 mt-6">
            {{-- Dates and renewal --}}
            <div class="space-y-1 text-base">
                <h4 class="text-lg font-semibold mb-2">{{ __('domains.portal.details') }}</h4>
                <div><span class="mr-2">{{ __('domains.portal.registered') }}:</span><span class="text-base/50">{{ $domain->registered_at?->format('d/m/Y') ?? '—' }}</span></div>
                <div><span class="mr-2">{{ __('domains.portal.expires') }}:</span><span class="text-base/50">{{ $domain->expires_at?->format('d/m/Y') ?? '—' }}</span></div>
                @if (in_array($domain->status, [\App\Enums\DomainStatus::Grace, \App\Enums\DomainStatus::Redemption], true))
                <div class="text-warning">{{ __('domains.portal.recoverable_until', ['date' => \App\Services\Domains\DomainReminders::lastDay($domain)->format('d/m/Y')]) }}</div>
                @endif
                <div class="flex items-center gap-2">
                    <span>{{ __('domains.portal.auto_renew') }}:</span>
                    <span class="text-base/50">{{ $domain->auto_renew ? __('domains.portal.on') : __('domains.portal.off_do_not_renew') }}</span>
                    @if ($canManage)
                    <button type="button" class="text-sm underline" wire:click="toggleAutoRenew"
                        wire:confirm="{{ $domain->auto_renew ? __('domains.portal.auto_renew_off_confirm') : __('domains.portal.auto_renew_on_confirm') }}">
                        {{ $domain->auto_renew ? __('domains.portal.turn_off') : __('domains.portal.turn_on') }}
                    </button>
                    @endif
                </div>
            </div>

            {{-- At the registrar --}}
            <div class="space-y-1 text-base">
                <h4 class="text-lg font-semibold mb-2">{{ __('domains.portal.registrar') }}</h4>
                <div class="flex items-center gap-2">
                    <span>{{ __('domains.portal.transfer_lock') }}:</span>
                    <span class="text-base/50">{{ $domain->transfer_lock ? __('domains.portal.on') : __('domains.portal.off') }}</span>
                    @if ($lockable)
                    <button type="button" class="text-sm underline" wire:click="toggleTransferLock"
                        @if ($domain->transfer_lock) wire:confirm="{{ __('domains.portal.unlock_confirm') }}" @endif>{{ $domain->transfer_lock ? __('domains.portal.turn_off') : __('domains.portal.turn_on') }}</button>
                    @endif
                </div>
                @if ($privacyOffered || $domain->id_protection)
                <div class="flex items-center gap-2">
                    <span>{{ __('domains.portal.id_protection') }}:</span>
                    <span class="text-base/50">{{ $domain->id_protection ? __('domains.portal.on') : __('domains.portal.off') }}</span>
                    @if ($privacyOffered)
                    <button type="button" class="text-sm underline" wire:click="toggleIdProtection">{{ $domain->id_protection ? __('domains.portal.turn_off') : __('domains.portal.turn_on') }}</button>
                    @endif
                </div>
                @endif
                @if ($showEpp)
                {{-- Not in the page: «Show» asks the registrar for it (revealEppCode); wire:ignore keeps it across re-renders. --}}
                <div class="flex items-center gap-2" x-data="{ shown: false, code: null }" wire:ignore>
                    <span>{{ __('domains.portal.epp_code') }}:</span>
                    <span class="text-base/50 select-all font-mono break-all" x-show="shown" x-cloak x-text="code"></span>
                    <span class="text-base/50" x-show="!shown">••••••••</span>
                    <button type="button" class="text-sm underline"
                        x-on:click="if (!shown && code === null) { code = await $wire.revealEppCode(); } shown = !shown && code !== null"
                        x-text="shown ? @js(__('services.hide')) : @js(__('services.show'))"></button>
                </div>
                <p class="text-xs text-base/50">{{ __('domains.portal.epp_help') }}</p>
                @elseif ($canManage)
                <p class="text-sm text-base/60">{{ __('domains.portal.not_manageable') }}</p>
                @endif
            </div>
        </div>

        {{-- Nameservers --}}
        <div class="mt-6">
            <div class="flex items-center gap-3 mb-2">
                <h4 class="text-lg font-semibold">{{ __('domains.portal.nameservers') }}</h4>
                @if ($manageable && !$editNameservers)
                <button type="button" class="text-sm underline" wire:click="$set('editNameservers', true)">{{ __('domains.portal.change') }}</button>
                @endif
            </div>
            @if ($editNameservers)
            <form wire:submit="saveNameservers" class="space-y-2 max-w-md">
                @foreach ($nameservers as $i => $ns)
                <div class="flex gap-2">
                    <x-form.input name="nameservers.{{ $i }}.host" wire:model="nameservers.{{ $i }}.host" placeholder="ns{{ $i + 1 }}.example.com" />
                    @if (count($nameservers) > 2)
                    <button type="button" class="text-sm underline" wire:click="removeNameserver({{ $i }})">{{ __('domains.portal.remove') }}</button>
                    @endif
                </div>
                @endforeach
                @error('nameservers') <p class="text-sm text-error">{{ $message }}</p> @enderror
                <div class="flex gap-3">
                    @if (count($nameservers) < 13)
                    <button type="button" class="text-sm underline" wire:click="addNameserver">{{ __('domains.portal.add_nameserver') }}</button>
                    @endif
                    <x-button.primary class="!w-fit" type="submit" wire:loading.attr="disabled">{{ __('domains.portal.save') }}</x-button.primary>
                    <button type="button" class="text-sm underline" wire:click="$set('editNameservers', false)">{{ __('domains.portal.cancel') }}</button>
                </div>
            </form>
            @else
            <ul class="text-base/60 font-mono text-sm space-y-0.5">
                @forelse ($domain->nameservers as $ns)
                <li>{{ $ns->host }}</li>
                @empty
                <li>—</li>
                @endforelse
            </ul>
            @endif
        </div>
    </div>
</div>
