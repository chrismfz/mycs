{{-- An Openprovider SSL certificate: its CSR and validation (once), what to publish, its status and the certificate. --}}
@if($hasSsl && $sslOrder)
<div class="bg-background-secondary rounded-lg p-4 mt-2">
    <h4 class="text-lg font-semibold">{{ __('services.ssl.title') }}</h4>

    @if($sslOrder->awaitingConfiguration())
        @can('update', $service)
        <p class="text-sm text-base/70 mt-1">{{ __('services.ssl.configure_hint') }}</p>
        <form wire:submit="{{ $sslName ? 'sslOrder' : 'sslCheck' }}" class="flex flex-col gap-3 mt-3">
            <x-form.textarea wire:model.blur="sslCsr" name="sslCsr" :label="__('services.ssl.csr')" rows="8" class="font-mono text-xs" required
                placeholder="-----BEGIN CERTIFICATE REQUEST-----" />
            @if($sslName)
            <p class="text-sm">{{ __('services.ssl.for_name') }} <strong>{{ $sslName }}</strong></p>
            <x-form.select wire:model.live="sslMethod" name="sslMethod" :label="__('services.ssl.method')" required>
                <option value="dns">{{ __('services.ssl.methods.dns') }}</option>
                <option value="https">{{ __('services.ssl.methods.https') }}</option>
                <option value="email">{{ __('services.ssl.methods.email') }}</option>
            </x-form.select>
            @if($sslMethod === 'email')
            <x-form.select wire:model="sslApprover" name="sslApprover" :label="__('services.ssl.approver')" required>
                <option value="">—</option>
                @foreach($sslApprovers as $approver)
                <option value="{{ $approver }}">{{ $approver }}</option>
                @endforeach
            </x-form.select>
            @endif
            <p class="text-xs text-base/50">{{ __('services.ssl.order_hint') }}</p>
            <x-button.primary type="submit" class="!w-fit" wire:loading.attr="disabled">{{ __('services.ssl.order') }}</x-button.primary>
            @else
            <x-button.primary type="submit" class="!w-fit" wire:loading.attr="disabled">{{ __('services.ssl.check') }}</x-button.primary>
            @endif
        </form>
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

        @if(!$sslOrder->issued())
        <div class="mt-3 text-sm">
            <p class="font-semibold">{{ __('services.ssl.validate_title') }}</p>
            @if($sslOrder->validation_method === 'email')
                <p class="mt-1">{{ __('services.ssl.validate_email', ['email' => $sslOrder->validation['approver'] ?? $sslOrder->approver_email]) }}</p>
                @can('update', $service)
                <x-button.secondary type="button" class="!w-fit mt-2" wire:click="sslResend" wire:loading.attr="disabled">{{ __('services.ssl.resend') }}</x-button.secondary>
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
        @else
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
        @endif
    @endif
</div>
@endif
