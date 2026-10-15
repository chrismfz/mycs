{{-- A nixpal license (WHMCS's licensing «Manage License»): its key, where it is bound, its status, Reissue (L2). --}}
@if($license = $service->license)
<div class="mt-4 border-t border-neutral pt-4 space-y-2">
    <h3 class="text-lg font-semibold">{{ __('services.license.title') }}</h3>
    <div class="flex items-center text-base" x-data="{ copied: false }">
        <span class="mr-2">{{ __('services.license.key') }}:</span>
        <code class="text-base/80 select-all">{{ $license->license_key }}</code>
        <button type="button" class="ml-2 text-sm underline"
            x-on:click="navigator.clipboard.writeText(@js($license->license_key)); copied = true; setTimeout(() => copied = false, 2000)"
            x-text="copied ? @js(__('services.license.copied')) : @js(__('services.license.copy'))"></button>
    </div>
    <div class="flex items-start text-base">
        <span class="mr-2">{{ __('services.license.valid_ips') }}:</span>
        <span class="text-base/50">{{ filled($license->valid_ip) ? str_replace(',', ', ', $license->valid_ip) : __('services.license.not_bound') }}</span>
    </div>
    <div class="flex items-center text-base">
        <span class="mr-2">{{ __('services.license.status') }}:</span>
        <span class="font-semibold @if($license->status === \App\Models\License::STATUS_ACTIVE) text-green-500 @elseif($license->status === \App\Models\License::STATUS_EXPIRED) text-red-500 @else text-orange-500 @endif">
            {{ __('services.license.statuses.' . strtolower($license->status)) }}
        </span>
    </div>
    @foreach($licenseFields as $name => $value)
    <div class="flex items-center text-base">
        <span class="mr-2">{{ $name }}:</span>
        <span class="text-base/50">{{ $value }}</span>
    </div>
    @endforeach
    @can('update', $service)
    @if(\App\Services\Licensing\Licenses::canReissue($license))
    <div>
        <x-button.primary wire:click="reissueLicense" wire:confirm="{{ __('services.license.reissue_confirm') }}" class="!w-fit">
            {{ __('services.license.reissue') }}
        </x-button.primary>
        <p class="text-sm text-base/50 mt-1">{{ __('services.license.reissue_help') }}</p>
    </div>
    @endif
    @endcan
</div>
@endif
