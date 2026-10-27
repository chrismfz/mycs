<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if(in_array(app()->getLocale(), config('app.rtl_locales'))) dir="rtl" @endif>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>
        {{ config('app.name') }}
        @isset($title)
        - {{ $title }}
        @endisset
    </title>
    @livewireStyles
    @vite(['resources/js/app.js', 'resources/css/app.css'])
    @include('layouts.colors')

    @if (config('settings.favicon'))
    <link rel="icon" href="{{ Storage::url(config('settings.favicon')) }}">
    @endif
    @isset($title)
    <meta content="{{ isset($title) ? config('app.name') . ' - ' . $title : config('app.name') }}" property="og:title">
    <meta content="{{ isset($title) ? config('app.name') . ' - ' . $title : config('app.name') }}" name="title">
    @endisset
    @isset($description)
    <meta content="{{ $description }}" property="og:description">
    <meta content="{{ $description }}" name="description">
    @endisset
    @isset($image)
    <meta content="{{ $image }}" property="og:image">
    <meta content="{{ $image }}" name="image">
    @endisset

    <meta name="theme-color" content="{{ theme('primary') }}">

    {{-- Appearance settings: staff-written CSS/HTML, output as it is (never compiled as Blade). --}}
    @if (filled(theme('custom_css')))
    <style>{!! str_ireplace('</style', '<\\/style', theme('custom_css')) !!}</style>
    @endif
    {{-- Staff's site-wide scripts (analytics, chat) never on a page whose URL is a secret: they read location.href (docs/tickets.md §3 Δ). --}}
    @php($privateLink = request()->routeIs(...\App\Http\Middleware\StartSessionWithoutPrivateLinks::PRIVATE_ROUTES))
    @unless ($privateLink)
        {!! theme('custom_head') !!}
        {!! hook('head') !!}
    @endunless
</head>

<body class="w-full bg-background text-base min-h-screen flex flex-col antialiased"
    x-cloak
    x-data="{
        theme: $persist('system').as('theme_mode'),
        systemDark: window.matchMedia('(prefers-color-scheme: dark)').matches,
        init() {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
                this.systemDark = e.matches;
            });
        },
        get isDark() {
            return this.theme === 'dark' || (this.theme === 'system' && this.systemDark);
        }
    }"
    :class="{'dark': isDark}"
>
    @unless ($privateLink)
        {!! hook('body') !!}
        {!! theme('custom_header') !!}
    @endunless
    <x-navigation />
    <div class="w-full flex flex-grow">
        @if (isset($sidebar) && $sidebar)
        <x-navigation.sidebar title="$title" />
        @endif
        <div class="{{ (isset($sidebar) && $sidebar) ? 'md:ml-64 rtl:ml-0 rtl:md:mr-64' : '' }} flex flex-col flex-grow overflow-auto">
            <main class="mt-16 grow">
                {{ $slot }}
            </main>
            <x-notification />
            <x-confirmation />
            <div class="flex">
                {{-- Staff's footer HTML (a chat widget, analytics) never on a page whose URL is a secret (AUTHN-4). --}}
                @if (filled(theme('custom_footer')) && !$privateLink)
                {!! theme('custom_footer') !!}
                @else
                <x-navigation.footer />
                @endif
            </div>
        </div>
        <x-impersonating />
        {{-- The live chat's bubble, for a login that may chat (docs/livechat.md §7); never on a private link's page. --}}
        @if (!$privateLink && auth('web')->check() && \App\Support\LiveChats::customerMay(auth('web')->user()))
        @livewire('client.chat-bubble', key('mycs-chat-bubble'))
        @endif
    </div>
    @livewireScriptConfig
    @unless ($privateLink)
        {!! hook('footer') !!}
    @endunless
</body>

</html>
