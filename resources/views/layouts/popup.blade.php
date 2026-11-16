{{--
    The live chat's popup (docs/livechat.md §4, step 4): our styles and Livewire, nothing else. No navigation, and never
    the staff's site-wide HTML or the extensions' hooks (analytics, a third-party chat): no script but ours runs where a
    visitor writes, and none can read the chat.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if(in_array(app()->getLocale(), config('app.rtl_locales'))) dir="rtl" @endif>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('app.name') }}@isset($title) - {{ $title }}@endisset</title>
    @livewireStyles
    @vite(['resources/js/app.js', 'resources/css/app.css'])
    @include('layouts.colors')
    @if (config('settings.favicon'))
    <link rel="icon" href="{{ Storage::url(config('settings.favicon')) }}">
    @endif
</head>

<body class="w-full bg-background text-base min-h-screen antialiased"
    x-cloak
    x-data="{
        theme: $persist('system').as('theme_mode'),
        systemDark: window.matchMedia('(prefers-color-scheme: dark)').matches,
        get isDark() { return this.theme === 'dark' || (this.theme === 'system' && this.systemDark) }
    }"
    :class="{'dark': isDark}">
    <main class="mx-auto flex min-h-screen w-full max-w-lg flex-col p-4">
        {{ $slot }}
    </main>
    @livewireScriptConfig
</body>

</html>
