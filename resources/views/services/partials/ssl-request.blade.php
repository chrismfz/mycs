{{--
    An SSL order's or reissue's request (docs/ssl-vault.md): a key and CSR made here for the domain, or the customer's
    own CSR pasted; once checked, the validation method and the button ($submit: sslOrder or sslReissue).
--}}
@if($sslName === null)
<div class="flex gap-1 border-b border-neutral mt-3" role="tablist">
    <button type="button" role="tab" wire:click="$set('sslMode', 'generate')" @class(['px-3 py-1.5 text-sm', 'border-b-2 border-primary font-semibold' => $sslMode === 'generate', 'text-base/60' => $sslMode !== 'generate'])>{{ __('services.ssl.vault.mode_generate') }}</button>
    <button type="button" role="tab" wire:click="$set('sslMode', 'paste')" @class(['px-3 py-1.5 text-sm', 'border-b-2 border-primary font-semibold' => $sslMode === 'paste', 'text-base/60' => $sslMode !== 'paste'])>{{ __('services.ssl.vault.mode_paste') }}</button>
</div>
@if($sslMode === 'generate')
<form wire:submit="sslGenerate" class="flex flex-col gap-3 mt-3">
    <x-form.input wire:model="sslDomain" name="sslDomain" :label="__('services.ssl.vault.domain')" placeholder="example.gr" required :readonly="$reissue" />
    <p class="text-xs text-base/50 -mt-2">{{ __('services.ssl.vault.domain_hint') }}</p>
    @if($reissue)
    <x-form.select wire:model.live="sslReissueKey" name="sslReissueKey" :label="__('services.ssl.vault.reissue_key')" required>
        <option value="new">{{ __('services.ssl.vault.reissue_key_new') }}</option>
        <option value="keep">{{ __('services.ssl.vault.reissue_key_keep') }}</option>
    </x-form.select>
    @endif
    @if(!$reissue || $sslReissueKey === 'new')
    <x-form.select wire:model="sslKeyType" name="sslKeyType" :label="__('services.ssl.vault.key_type')" required>
        <option value="rsa2048">{{ __('services.ssl.vault.key_types.rsa2048') }}</option>
        <option value="ec-p256">{{ __('services.ssl.vault.key_types.ec-p256') }}</option>
    </x-form.select>
    @endif
    <p class="text-xs text-base/70 bg-background rounded p-2">{{ __('services.ssl.vault.kept_note') }}</p>
    <x-button.primary type="submit" class="!w-fit" wire:loading.attr="disabled">{{ __('services.ssl.vault.generate') }}</x-button.primary>
</form>
@else
<form wire:submit="sslCheck" class="flex flex-col gap-3 mt-3">
    <x-form.textarea wire:model.blur="sslCsr" name="sslCsr" :label="__('services.ssl.csr')" rows="8" class="font-mono text-xs" required
        placeholder="-----BEGIN CERTIFICATE REQUEST-----" />
    <x-button.primary type="submit" class="!w-fit" wire:loading.attr="disabled">{{ __('services.ssl.check') }}</x-button.primary>
</form>
@endif
@else
<form wire:submit="{{ $submit }}" class="flex flex-col gap-3 mt-3">
    <p class="text-sm">{{ __('services.ssl.for_name') }} <strong>{{ $sslName }}</strong></p>
    <details>
        <summary class="text-xs text-base/60 cursor-pointer">{{ __('services.ssl.csr') }}</summary>
        <textarea readonly rows="6" class="w-full font-mono text-xs bg-background rounded p-2 mt-1">{{ $sslCsr }}</textarea>
    </details>
    @include('services.partials.ssl-method')
    @if(!$reissue)
    <p class="text-xs text-base/50">{{ __('services.ssl.order_hint') }}</p>
    @endif
    <div class="flex flex-wrap gap-2">
        <x-button.primary type="submit" class="!w-fit" wire:loading.attr="disabled">{{ $reissue ? __('services.ssl.reissue') : __('services.ssl.order') }}</x-button.primary>
        <x-button.secondary type="button" class="!w-fit" wire:click="$set('sslCsr', '')">{{ __('services.ssl.vault.start_again') }}</x-button.secondary>
    </div>
</form>
@endif
