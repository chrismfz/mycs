{{-- Utilities → SSL Checker (SslChecker): a key, a CSR and a certificate checked together; nothing kept. --}}
<x-filament-panels::page>
    <form wire:submit="check" class="flex flex-col gap-4">
        <p class="text-sm text-gray-500">Paste any of the three. Nothing pasted here is kept, and the key is cleared from this page as soon as it is checked.</p>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            @foreach(['key' => ['Private key', '-----BEGIN PRIVATE KEY-----'], 'csr' => ['CSR', '-----BEGIN CERTIFICATE REQUEST-----'], 'certificate' => ['Certificate (and its CA bundle)', '-----BEGIN CERTIFICATE-----']] as $field => [$label, $placeholder])
            <label class="flex flex-col gap-1 min-w-0">
                <span class="text-sm font-medium">{{ $label }}</span>
                <textarea wire:model="{{ $field }}" rows="10" placeholder="{{ $placeholder }}" autocomplete="off" spellcheck="false"
                    class="w-full font-mono text-xs rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700 p-2"></textarea>
            </label>
            @endforeach
        </div>
        <x-filament::button type="submit" class="w-fit" wire:loading.attr="disabled">Check</x-filament::button>
    </form>

    @if ($error)
    <p class="text-danger-600">{{ $error }}</p>
    @endif

    @if ($results !== [])
    <x-filament::section>
        <x-slot name="heading">Result</x-slot>
        <x-ssl-check-results :results="$results" />
    </x-filament::section>
    @endif
</x-filament-panels::page>
