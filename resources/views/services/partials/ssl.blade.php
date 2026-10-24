{{-- An Openprovider SSL certificate: its CSR and validation (once), what to publish, its status and the certificate. --}}
@if($hasSsl && $sslOrder)
<div class="bg-background-secondary rounded-lg p-4 mt-2">
    <h4 class="text-lg font-semibold">{{ __('services.ssl.title') }}</h4>

    @if($sslOrder->awaitingConfiguration())
        @can('update', $service)
        <p class="text-sm text-base/70 mt-1">{{ __('services.ssl.vault.configure_hint') }}</p>
        @include('services.partials.ssl-request', ['reissue' => false, 'submit' => 'sslOrder'])
        @else
        <p class="text-sm text-base/70 mt-1">{{ __('services.ssl.awaiting') }}</p>
        @endcan
    @elseif($sslOrder->order_id === null)
        <p class="text-sm text-base/70 mt-1">{{ __('services.ssl.pending') }}</p>
    @else
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm mt-3">
            <dt class="text-base/50">{{ __('services.ssl.name') }}</dt><dd>{{ $sslOrder->common_name }}</dd>
            <dt class="text-base/50">{{ __('services.ssl.status') }}</dt><dd>{{ \Illuminate\Support\Facades\Lang::has('services.ssl.statuses.' . $sslOrder->status) ? __('services.ssl.statuses.' . $sslOrder->status) : $sslOrder->status }}</dd>
            @if($sslOrder->expires_at)
            <dt class="text-base/50">{{ __('services.ssl.expires') }}</dt><dd>{{ $sslOrder->expires_at->toDateString() }}</dd>
            @endif
            @if($sslOrder->order_ends_at)
            <dt class="text-base/50">{{ __('services.ssl.order_ends') }}</dt><dd>{{ $sslOrder->order_ends_at->toDateString() }}</dd>
            @endif
            @if($sslOrder->reissue_at && !$sslOrder->reissue_at->isPast())
            <dt class="text-base/50">{{ __('services.ssl.reissue') }}</dt><dd>{{ $sslOrder->reissue_at->toDateString() }}</dd>
            @endif
        </dl>

        {{-- Waiting for validation (a new order, a renewal's or a reissue's): what to publish, even while a certificate is in use. --}}
        @if(!$sslOrder->issued() || $sslOrder->waiting())
        <div class="mt-3 text-sm">
            <p class="font-semibold">{{ __('services.ssl.validate_title') }}</p>
            @if($sslOrder->validation_method === 'email')
                <p class="mt-1">{{ __('services.ssl.validate_email', ['email' => $sslOrder->validation['approver'] ?? $sslOrder->approver_email]) }}</p>
                @can('update', $service)
                <div class="flex flex-wrap gap-2 mt-2">
                    <x-button.secondary type="button" class="!w-fit" wire:click="sslResend" wire:loading.attr="disabled">{{ __('services.ssl.resend') }}</x-button.secondary>
                    @if($sslApprovers === [])
                    <x-button.secondary type="button" class="!w-fit" wire:click="sslLoadApprovers" wire:loading.attr="disabled">{{ __('services.ssl.approver_other') }}</x-button.secondary>
                    @endif
                </div>
                @if($sslApprovers !== [])
                <form wire:submit="sslChangeApprover" class="flex flex-col gap-2 mt-2">
                    <x-form.select wire:model="sslApprover" name="sslApprover" :label="__('services.ssl.approver')" required>
                        <option value="">—</option>
                        @foreach($sslApprovers as $approver)
                        <option value="{{ $approver }}">{{ $approver }}</option>
                        @endforeach
                    </x-form.select>
                    <x-button.primary type="submit" class="!w-fit" wire:loading.attr="disabled">{{ __('services.ssl.approver_change') }}</x-button.primary>
                </form>
                @endif
                @endcan
            @else
                @forelse($sslOrder->validation['data'] ?? [] as $item)
                    @if($sslOrder->validation_method === 'dns' && filled($item['dns_value'] ?? null))
                    <p class="mt-1">{{ __('services.ssl.validate_dns') }}</p>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 font-mono text-xs break-all bg-background rounded p-2 mt-1">
                        <dt class="text-base/50">{{ __('services.ssl.dns_name') }}</dt><dd>{{ $item['dns_record'] ?? '' }}</dd>
                        <dt class="text-base/50">{{ __('services.ssl.dns_type') }}</dt><dd>CNAME</dd>
                        <dt class="text-base/50">{{ __('services.ssl.dns_value') }}</dt><dd>{{ $item['dns_value'] }}</dd>
                    </dl>
                    @elseif($sslOrder->validation_method === 'https' && filled($item['file_location'] ?? null))
                    <p class="mt-1">{{ __('services.ssl.validate_https') }}</p>
                    <div class="font-mono text-xs break-all bg-background rounded p-2 mt-1">{{ $item['file_location'] }}</div>
                    <textarea readonly rows="3" class="w-full font-mono text-xs bg-background rounded p-2 mt-1">{{ $item['file_contents'] ?? $item['file_content'] ?? '' }}</textarea>
                    @endif
                @empty
                    <p class="mt-1 text-base/70">{{ __('services.ssl.validate_soon') }}</p>
                @endforelse
            @endif
        </div>
        @endif
        @if($sslOrder->issued())
        <div class="mt-3 grid md:grid-cols-2 gap-2">
            <div>
                <p class="text-sm font-semibold">{{ __('services.ssl.certificate') }}</p>
                <textarea readonly rows="8" class="w-full font-mono text-xs bg-background rounded p-2 mt-1">{{ $sslOrder->certificate }}</textarea>
            </div>
            @if($sslOrder->intermediate)
            <div>
                <p class="text-sm font-semibold">{{ __('services.ssl.intermediate') }}</p>
                <textarea readonly rows="8" class="w-full font-mono text-xs bg-background rounded p-2 mt-1">{{ $sslOrder->intermediate }}</textarea>
            </div>
            @endif
        </div>
        @can('update', $service)
        @if($sslOrder->status === 'ACT' && (!$sslOrder->order_ends_at || $sslOrder->order_ends_at->isFuture()) && $sslOrder->renew_requested_at === null)
        <details class="mt-3">
            <summary class="text-sm font-semibold cursor-pointer">{{ __('services.ssl.reissue_title') }}</summary>
            <p class="text-xs text-base/50 mt-1">{{ __('services.ssl.reissue_hint') }}</p>
            @include('services.partials.ssl-request', ['reissue' => true, 'submit' => 'sslReissue'])
        </details>
        @endif
        @endcan
        @endif
    @endif

    @if($sslVersions->isNotEmpty())
        @include('services.partials.ssl-files')
    @endif
</div>
@endif
