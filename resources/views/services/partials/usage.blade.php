{{-- Its usage as its server last told it (services:usage; WHMCS's «Usage Statistics»): disk, this month's bandwidth, a reseller's accounts. --}}
@php($usage = in_array($service->status, ['active', 'suspended'], true) ? $service->usage : null)
@if($usage)
@php($meters = array_filter([
    ['label' => __('services.usage.disk'), 'used' => $usage->disk_used_mb, 'limit' => $usage->disk_limit_mb, 'size' => true],
    ['label' => __('services.usage.bandwidth'), 'used' => $usage->bandwidth_used_mb, 'limit' => $usage->bandwidth_limit_mb, 'size' => true],
    $usage->accounts !== null ? ['label' => __('services.usage.' . ($usage->accounts_kind === 'mailboxes' ? 'mailboxes' : 'accounts')), 'used' => $usage->accounts, 'limit' => $usage->accounts_limit, 'size' => false] : null,
    // Its files' count (Hosting Accounts): a limit a site meets before its disk's.
    ($account = $service->cpanelAccount) && $account->inodes_used !== null && $account->inodes_limit ? ['label' => __('services.usage.inodes'), 'used' => $account->inodes_used, 'limit' => $account->inodes_limit, 'size' => false] : null,
]))
@php($near = isset($account) && $account ? $account->nearLimits() : [])
<div class="bg-background-secondary rounded-lg p-4 mt-2">
    <h4 class="text-lg font-semibold">{{ __('services.usage.title') }}</h4>
    <div class="grid {{ count($meters) >= 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }} gap-4 mt-3">
        @foreach($meters as $meter)
        @php($percent = \App\Models\ServiceUsage::percent($meter['used'], $meter['limit']))
        <div>
            <div class="flex justify-between text-sm">
                <span class="font-semibold">{{ $meter['label'] }}</span>
                <span class="text-base/50">
                    {{ $meter['size'] ? \App\Models\ServiceUsage::size($meter['used']) : ($meter['used'] ?? '—') }}
                    / {{ $meter['limit'] === null ? __('services.usage.unlimited') : ($meter['size'] ? \App\Models\ServiceUsage::size($meter['limit']) : $meter['limit']) }}
                </span>
            </div>
            @php($text = ($meter['size'] ? \App\Models\ServiceUsage::size($meter['used']) : ($meter['used'] ?? '—')) . ' / ' . ($meter['limit'] === null ? __('services.usage.unlimited') : ($meter['size'] ? \App\Models\ServiceUsage::size($meter['limit']) : $meter['limit'])))
            <div class="h-2 rounded-full bg-neutral mt-1 overflow-hidden" role="progressbar" aria-label="{{ $meter['label'] }}"
                aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent ?? 0 }}" aria-valuetext="{{ $text }}">
                <div class="h-2 rounded-full {{ $percent !== null && $percent >= 90 ? 'bg-error' : ($percent !== null && $percent >= 75 ? 'bg-warning' : 'bg-primary') }}"
                    style="width: {{ $percent ?? 0 }}%"></div>
            </div>
        </div>
        @endforeach
    </div>
    @if($near !== [])
    <p class="text-sm text-warning mt-3">
        {{ __('services.usage.near', ['limits' => collect($near)->map(fn ($limit) => __('services.usage.limits.' . $limit))->implode(', ')]) }}
        @can('update', $service)<a class="underline" href="{{ route('services.upgrade', $service) }}" wire:navigate>{{ __('services.usage.upgrade') }}</a>@endcan
    </p>
    @endif
    <p class="text-xs text-base/50 mt-3">{{ __('services.usage.read_at', ['when' => $usage->read_at->format('d/m/Y H:i')]) }}</p>
</div>
@endif
