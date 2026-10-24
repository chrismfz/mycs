{{-- A pasted CSR or certificate read (SslVault::decode): nothing kept, never a key. --}}
<div class="mt-4 border-t border-neutral pt-3">
    <details @if($sslDecoded !== []) open @endif>
        <summary class="text-sm cursor-pointer">{{ __('services.ssl.checks.decode_title') }}</summary>
        <form wire:submit="sslDecode" class="mt-2 flex flex-col gap-2">
            <p class="text-xs text-base/60">{{ __('services.ssl.checks.decode_hint') }}</p>
            <x-form.textarea wire:model="sslDecodeText" name="sslDecodeText" rows="6" class="font-mono text-xs" placeholder="-----BEGIN CERTIFICATE-----" />
            <x-button.secondary type="submit" class="!w-fit" wire:loading.attr="disabled">{{ __('services.ssl.checks.decode') }}</x-button.secondary>
            @if($sslDecoded !== [])
            <x-ssl-check-results :results="$sslDecoded" />
            @endif
        </form>
    </details>

</div>
