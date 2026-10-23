<div class="container mt-14 max-w-3xl mx-auto space-y-6">
    <div class="text-center space-y-2">
        <h1 class="text-3xl font-semibold">{{ __('domains.shop.whois_title') }}</h1>
        <p class="text-base/60">{{ __('domains.shop.whois_lead') }}</p>
    </div>

    <form wire:submit="lookup" class="flex gap-2">
        <input type="search" wire:model="query" maxlength="255" placeholder="example.com"
            class="flex-grow bg-background-secondary border border-neutral rounded-lg px-4 py-3 text-lg" />
        <x-button.primary class="!w-fit px-6" type="submit" wire:loading.attr="disabled">{{ __('domains.shop.lookup') }}</x-button.primary>
    </form>
    {{-- The captcha a lookup asks for: the chosen one, or ours (Captchable::requireCaptchaOrAltcha). --}}
    <div class="flex flex-col items-center">
        @if (\App\Livewire\DomainShop\Whois::captchaKind() === 'altcha' && config('settings.captcha') !== 'altcha')
        <div wire:ignore><x-captcha.altcha /></div>
        @else
        <x-captcha :form="'whois'" />
        @endif
        @error('captcha')
        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
        @enderror
    </div>

    @if ($error)
    <p class="text-center text-error">{{ $error }}</p>
    @endif

    @if ($answer)
    <div class="bg-background-secondary border border-neutral rounded-lg p-6 space-y-2">
        <h2 class="text-xl font-semibold">{{ $fqdn }}</h2>
        @if ($answer['status'] === 'not_found')
        <p class="text-success">{{ __('domains.shop.whois_free') }}
            <a href="{{ route('domain.search', ['domain' => $fqdn]) }}" wire:navigate class="underline">{{ __('domains.shop.search_it') }}</a></p>
        @elseif ($answer['status'] === 'not_listed')
        <p class="text-base/80">{{ __('domains.shop.whois_not_listed') }}
            <a href="{{ route('domain.search', ['domain' => $fqdn]) }}" wire:navigate class="underline">{{ __('domains.shop.search_it') }}</a></p>
        @elseif ($answer['status'] === 'unsupported')
        <p class="text-base/60">{{ __('domains.shop.whois_unsupported') }}</p>
        @elseif ($answer['status'] === 'error' || $answer['status'] === 'invalid')
        <p class="text-base/60">{{ __('domains.shop.whois_error') }}</p>
        @elseif ($answer['record'])
        @php($r = $answer['record'])
        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-1 text-sm">
            <dt class="text-base/60">{{ __('domains.shop.registrar') }}</dt><dd class="sm:col-span-2">{{ $r['registrar'] ?? '—' }}</dd>
            <dt class="text-base/60">{{ __('domains.shop.registered') }}</dt><dd class="sm:col-span-2">{{ $r['registered'] ?? '—' }}</dd>
            <dt class="text-base/60">{{ __('domains.shop.expires') }}</dt><dd class="sm:col-span-2">{{ $r['expires'] ?? '—' }}</dd>
            <dt class="text-base/60">{{ __('domains.shop.changed') }}</dt><dd class="sm:col-span-2">{{ $r['changed'] ?? '—' }}</dd>
            <dt class="text-base/60">{{ __('domains.shop.status') }}</dt><dd class="sm:col-span-2">{{ implode(', ', (array) ($r['status'] ?? [])) ?: '—' }}</dd>
            <dt class="text-base/60">{{ __('domains.shop.nameservers') }}</dt><dd class="sm:col-span-2 font-mono">{{ implode(', ', (array) ($r['nameservers'] ?? [])) ?: '—' }}</dd>
            <dt class="text-base/60">DNSSEC</dt><dd class="sm:col-span-2">{{ ($r['dnssec'] ?? null) === null ? '—' : ($r['dnssec'] ? __('domains.portal.on') : __('domains.portal.off')) }}</dd>
        </dl>
        @elseif ($answer['text'])
        <pre class="text-xs whitespace-pre-wrap break-all bg-background p-3 rounded">{{ $answer['text'] }}</pre>
        @endif
    </div>
    @endif
</div>
