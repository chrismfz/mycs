{{-- The domains its account hosts, as its server last told them (services:usage; owner 2026-10-22): main, addon, aliases;
     subdomains apart; a reseller's grouped by account. --}}
@php($hosted = in_array($service->status, ['active', 'suspended'], true) ? $service->hostedDomains : collect())
@if($hosted->isNotEmpty())
@php($accounts = $hosted->groupBy('account'))
<div class="bg-background-secondary rounded-lg p-4 mt-2">
    <h4 class="text-lg font-semibold">{{ __('services.hosted.title') }}</h4>
    @foreach($accounts as $account => $domains)
    <div class="mt-3">
        @if($accounts->count() > 1)
        <p class="text-sm font-semibold">{{ __('services.hosted.account', ['account' => $account]) }}</p>
        @endif
        <ul class="flex flex-wrap gap-2 mt-1">
            @foreach($domains->where('type', '!=', 'sub') as $hostedDomain)
            <li class="text-sm px-2 py-1 rounded bg-neutral">
                {{ $hostedDomain->displayName() }}
                <span class="text-xs text-base/50">· {{ __('services.hosted.types.' . $hostedDomain->type) }}</span>
            </li>
            @endforeach
        </ul>
        @if(($subs = $domains->where('type', 'sub'))->isNotEmpty())
        <details class="mt-1">
            <summary class="text-xs text-base/70 cursor-pointer">{{ __('services.hosted.subdomains', ['count' => $subs->count()]) }}</summary>
            <p class="text-xs text-base/70 mt-1">{{ $subs->map->displayName()->implode(', ') }}</p>
        </details>
        @endif
    </div>
    @endforeach
    <p class="text-xs text-base/50 mt-3">{{ __('services.usage.read_at', ['when' => $hosted->max('read_at')->format('d/m/Y H:i')]) }}</p>
</div>
@endif
