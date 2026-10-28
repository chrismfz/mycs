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
{{-- A reseller's meters are its accounts' summed against its product's limits: its own account's limits are not them. --}}
@php($near = isset($account) && $account && !$account->reseller ? $account->nearLimits() : [])
{{-- CloudLinux's limits (cpanel:lve): the peak of the last 24 hours of each; a reseller's own account's are not its accounts'. --}}
@php($lve = isset($account) && $account && !$account->reseller ? $account->lve() : [])
@php($near = [...$near, ...array_keys(array_filter($lve, fn ($l) => \App\Models\CpanelAccount::near($l['peak'], $l['limit'])))])
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
    @if($lve !== [])
    <h5 class="text-sm font-semibold mt-5">{{ __('services.usage.lve_title') }}</h5>
    <div class="grid sm:grid-cols-3 gap-4 mt-2">
        @foreach($lve as $key => $meter)
        @php($format = fn ($value) => $value === null ? '—' : match ($key) {
            'cpu' => $value . '%',
            'memory' => \App\Models\ServiceUsage::size($value),
            'io' => ($value < 1024 ? $value . ' KB' : \App\Models\ServiceUsage::size((int) round($value / 1024))) . '/s',
            default => $value,
        })
        @php($percent = \App\Models\ServiceUsage::percent($meter['peak'], $meter['limit']))
        @php($text = $key === 'cpu' ? __('services.usage.lve_cpu_of', ['percent' => $format($meter['peak'])]) : $format($meter['peak']) . ' / ' . ($meter['limit'] === null ? __('services.usage.unlimited') : $format($meter['limit'])))
        <div>
            <div class="flex justify-between text-sm">
                <span class="font-semibold">{{ __('services.usage.lve.' . $key) }}</span>
                <span class="text-base/50">{{ $text }}</span>
            </div>
            <div class="h-2 rounded-full bg-neutral mt-1 overflow-hidden" role="progressbar" aria-label="{{ __('services.usage.lve.' . $key) }}"
                aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent ?? 0 }}" aria-valuetext="{{ $text }}">
                <div class="h-2 rounded-full {{ $percent !== null && $percent >= 90 ? 'bg-error' : ($percent !== null && $percent >= 75 ? 'bg-warning' : 'bg-primary') }}"
                    style="width: {{ $percent ?? 0 }}%"></div>
            </div>
            <p class="text-xs text-base/50 mt-1">{{ __('services.usage.lve_now', ['value' => $format($meter['now'])]) }}</p>
        </div>
        @endforeach
    </div>
    @endif
    @if($near !== [])
    <p class="text-sm text-warning mt-3">
        {{ __('services.usage.near', ['limits' => collect($near)->map(fn ($limit) => __('services.usage.limits.' . $limit))->implode(', ')]) }}
        @can('update', $service)<a class="underline" href="{{ route('services.upgrade', $service) }}" wire:navigate>{{ __('services.usage.upgrade') }}</a>@endcan
    </p>
    @endif
    <p class="text-xs text-base/50 mt-3">{{ __('services.usage.read_at', ['when' => $usage->read_at->format('d/m/Y H:i')]) }}</p>
</div>
@endif
