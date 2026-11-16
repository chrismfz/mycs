{{--
    Settings → «Code for the site» (ChatLauncherCode, docs/livechat.md §4). The preview is ChatLauncher's own escaped
    button (every value checked there), the one raw output here; the code is shown as text.
--}}
<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @unless ($enabled)
        <p class="text-sm text-warning-600">{{ __('Live chat for visitors is off in Settings: the button would open a popup that says chat is not available.') }}</p>
        @endunless
        @if ($sites === [])
        <p class="text-sm text-gray-500">{{ __('List the sites first: Settings → Sites with the chat launcher.') }}</p>
        @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium">{{ __('Site') }}</span>
                <select wire:model.live="site" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900">
                    @foreach ($sites as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium">{{ __('Department') }}</span>
                <select wire:model.live="department" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900">
                    <option value="">{{ __('The first with someone available') }}</option>
                    @foreach ($departments as $option)
                    <option value="{{ (int) $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium">{{ __('Text') }}</span>
                <input type="text" wire:model.live.debounce.400ms="text" maxlength="{{ \App\Support\ChatLauncher::TEXT_MAX }}"
                    class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900">
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium">{{ __('Position') }}</span>
                <select wire:model.live="position" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900">
                    <option value="right">{{ __('Bottom right') }}</option>
                    <option value="left">{{ __('Bottom left') }}</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium">{{ __('Colour') }}</span>
                <input type="color" wire:model.live="colour" class="h-9 w-20 rounded-lg border-gray-300 dark:border-white/10">
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium">{{ __('Form') }}</span>
                <select wire:model.live="form" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900">
                    <option value="html">{{ __('HTML with inline script (recommended)') }}</option>
                    <option value="astro">{{ __('Astro component') }}</option>
                    <option value="script">{{ __('Script from MyCS with SRI (for a CSP without inline scripts)') }}</option>
                </select>
            </label>
        </div>

        @if ($code === null)
        <p class="text-sm text-danger-600">{{ __('Choose a listed site, a text of up to :max characters on one line, and a colour.', ['max' => \App\Support\ChatLauncher::TEXT_MAX]) }}</p>
        @else
        <x-filament::section>
            <x-slot name="heading">{{ __('Preview') }}</x-slot>
            <div class="rounded-lg border border-dashed border-gray-300 p-6 dark:border-white/10">{!! $preview !!}</div>
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">{{ __('Code') }}</x-slot>
            <div x-data="{ copied: false }" class="flex flex-col gap-2">
                <pre x-ref="code" class="max-h-96 overflow-auto rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/5"><code>{{ $code }}</code></pre>
                <x-filament::button class="w-fit" x-on:click="navigator.clipboard.writeText($refs.code.textContent).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                    <span x-show="!copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                </x-filament::button>
                @if ($form === 'script')
                <p class="text-xs text-gray-500">{{ __('Script: :url, integrity :hash. A new version is a new file; the hash of this one never changes.', ['url' => $scriptUrl, 'hash' => $integrity]) }}</p>
                @endif
                <p class="text-xs text-gray-500">{{ __('No secrets in it, and it passes no identity: customers prove who they are only by signing in to MyCS. To change a button already on a site, make the code again and paste it again.') }}</p>
            </div>
        </x-filament::section>
        @endif
        @endif
    </div>
</x-filament-panels::page>
