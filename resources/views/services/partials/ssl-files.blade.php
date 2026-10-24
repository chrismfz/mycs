{{--
    The SSL vault's files (docs/ssl-vault.md): the version in use, its downloads, and every version ordered (and the last
    key made since). The key and the .pfx only for a login that may have them ($sslMayKey).
--}}
@php
    $sslLink = fn ($version, $file) => route('services.ssl.file', ['service' => $service, 'version' => $version, 'file' => $file]);
@endphp
<div class="mt-4 border-t border-neutral pt-3">
    <p class="text-sm font-semibold">{{ __('services.ssl.vault.files') }}</p>
    @if($sslCurrent)
    <p class="text-xs text-base/60 mt-1">
        {{ __('services.ssl.vault.version', ['number' => $sslCurrent->number]) }}
        · {{ $sslCurrent->key_description ?? '—' }}
        @if($sslCurrent->spki_sha256)
        · <span class="font-mono" title="{{ __('services.ssl.vault.fingerprint') }}">{{ substr($sslCurrent->spki_sha256, 0, 4) }}…{{ substr($sslCurrent->spki_sha256, -4) }}</span>
        @endif
        · {{ __('services.ssl.vault.sources.' . $sslCurrent->source) }}
    </p>
    <div class="flex flex-wrap gap-2 mt-2">
        @foreach(['crt', 'ca-bundle', 'fullchain', 'csr'] as $file)
            @if($sslCurrent->publicFile($file) !== null)
            <a href="{{ $sslLink($sslCurrent, $file) }}" class="text-sm px-3 py-1.5 rounded-md bg-background border border-neutral hover:border-primary">{{ __('services.ssl.vault.file.' . $file) }}</a>
            @endif
        @endforeach
        @if($sslMayKey && $sslCurrent->hasKey())
            <a href="{{ $sslLink($sslCurrent, 'key') }}" class="text-sm px-3 py-1.5 rounded-md bg-background border border-neutral hover:border-primary">{{ __('services.ssl.vault.file.key') }}</a>
        @endif
    </div>
    @if($sslMayKey && $sslCurrent->hasKey() && filled($sslCurrent->certificate))
    <details class="mt-2" @if($errors->has('password')) open @endif>
        <summary class="text-sm cursor-pointer">{{ __('services.ssl.vault.file.pfx') }}</summary>
        <form method="POST" action="{{ route('services.ssl.pfx', ['service' => $service, 'version' => $sslCurrent]) }}" class="flex flex-col gap-2 mt-2 max-w-sm">
            @csrf
            <p class="text-xs text-base/60">{{ __('services.ssl.vault.pfx_hint') }}</p>
            <x-form.input type="password" name="password" :label="__('services.ssl.vault.pfx_password')" required minlength="8" autocomplete="new-password" />
            <x-form.input type="password" name="password_confirmation" :label="__('services.ssl.vault.pfx_password_again')" required minlength="8" autocomplete="new-password" />
            <x-button.secondary type="submit" class="!w-fit">{{ __('services.ssl.vault.pfx_download') }}</x-button.secondary>
        </form>
    </details>
    @endif
    @endif

    @if($sslVersions->count() > 1 || ($sslVersions->first() && $sslVersions->first()->id !== $sslCurrent?->id))
    <details class="mt-3">
        <summary class="text-sm cursor-pointer">{{ __('services.ssl.vault.history') }}</summary>
        <div class="overflow-x-auto mt-2">
        <table class="w-full text-xs">
            <thead class="text-base/50 text-left">
                <tr><th class="py-1 pr-3">#</th><th class="py-1 pr-3">{{ __('services.ssl.vault.key') }}</th><th class="py-1 pr-3">{{ __('services.ssl.vault.state') }}</th><th class="py-1 pr-3">{{ __('services.ssl.vault.valid_until') }}</th><th class="py-1">{{ __('services.ssl.vault.files') }}</th></tr>
            </thead>
            <tbody>
            @foreach($sslVersions as $version)
                <tr class="border-t border-neutral">
                    <td class="py-1 pr-3">{{ $version->number }}</td>
                    <td class="py-1 pr-3">{{ $version->hasKey() ? ($version->key_description ?? '—') : __('services.ssl.vault.no_key_here') }}</td>
                    <td class="py-1 pr-3">
                        @if($version->id === $sslCurrent?->id) {{ __('services.ssl.vault.states.current') }}
                        @elseif($version->ordered_at === null) {{ __('services.ssl.vault.states.draft') }}
                        @else {{ __('services.ssl.vault.states.replaced') }}
                        @endif
                    </td>
                    <td class="py-1 pr-3">{{ $version->expires_at?->toDateString() ?? '—' }}</td>
                    <td class="py-1 flex flex-wrap gap-2">
                        @foreach(['crt', 'ca-bundle', 'csr'] as $file)
                            @if($version->publicFile($file) !== null)
                            <a href="{{ $sslLink($version, $file) }}" class="underline">{{ $file }}</a>
                            @endif
                        @endforeach
                        @if($sslMayKey && $version->hasKey())
                            <a href="{{ $sslLink($version, 'key') }}" class="underline">key</a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    </details>
    @endif
</div>
