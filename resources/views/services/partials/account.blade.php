{{-- The service's own details, as WHMCS's product details: domain, IPs and, for whoever may manage it, the login. --}}
@if(filled($service->domain) && $service->domain !== $service->license?->license_key)
<div class="flex items-center text-base">
    <span class="mr-2">{{ __('services.domain') }}:</span>
    <span class="text-base/50">{{ $service->domain }}</span>
</div>
@endif
@if(filled($service->dedicated_ip))
<div class="flex items-center text-base">
    <span class="mr-2">{{ __('services.dedicated_ip') }}:</span>
    <span class="text-base/50">{{ $service->dedicated_ip }}</span>
</div>
@endif
@if(filled($service->assigned_ips))
<div class="flex items-start text-base">
    <span class="mr-2">{{ __('services.assigned_ips') }}:</span>
    <span class="text-base/50 whitespace-pre-line">{{ $service->assigned_ips }}</span>
</div>
@endif
@can('update', $service)
@if(filled($service->username))
<div class="flex items-center text-base">
    <span class="mr-2">{{ __('services.username') }}:</span>
    <span class="text-base/50">{{ $service->username }}</span>
</div>
@endif
@if(filled($service->password))
{{-- Not in the page: «Show» asks the server for it (revealPassword); wire:ignore keeps it shown across re-renders. --}}
<div class="flex items-center text-base" x-data="{ shown: false, password: null }" wire:ignore>
    <span class="mr-2">{{ __('services.password') }}:</span>
    <span class="text-base/50 select-all" x-show="shown" x-cloak x-text="password"></span>
    <span class="text-base/50" x-show="!shown">••••••••</span>
    <button type="button" class="ml-2 text-sm underline"
        x-on:click="if (!shown && password === null) { password = await $wire.revealPassword(); } shown = !shown && password !== null"
        x-text="shown ? @js(__('services.hide')) : @js(__('services.show'))"></button>
</div>
@endif
@endcan
