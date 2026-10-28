{{-- A VPS's console (docs/proxmox.md, B6): noVNC in its own tab, through the relay; each connection asks a ticket. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>{{ __('services.proxmox.console.title') }} · {{ $service->label }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/console-entry.js'])
    @include('layouts.colors')
</head>
<body class="bg-black text-white h-screen flex flex-col">
    <div id="console" class="flex flex-col h-full" data-ticket="{{ route('services.console.ticket', $service) }}"
        data-strings="{{ json_encode([
            'connecting' => __('services.proxmox.console.connecting'),
            'connected' => __('services.proxmox.console.connected'),
            'disconnected' => __('services.proxmox.console.disconnected'),
            'failed' => __('services.proxmox.console.not_opened'),
        ]) }}">
        <div class="flex flex-wrap items-center gap-2 px-3 py-2 bg-neutral-900 text-sm">
            <a href="{{ route('services.show', $service) }}" class="font-semibold hover:underline">{{ $service->label }}</a>
            <span data-status class="text-white/60">{{ __('services.proxmox.console.connecting') }}</span>
            <span class="flex-1"></span>
            <button type="button" data-action="ctrlaltdel" class="rounded px-2 py-0.5 border border-white/20 hover:bg-white/10">Ctrl+Alt+Del</button>
            <button type="button" data-action="reconnect" class="rounded px-2 py-0.5 border border-white/20 hover:bg-white/10">{{ __('services.proxmox.console.reconnect') }}</button>
            <button type="button" data-action="fullscreen" class="rounded px-2 py-0.5 border border-white/20 hover:bg-white/10">{{ __('services.proxmox.console.fullscreen') }}</button>
        </div>
        <div data-screen class="flex-1 min-h-0"></div>
    </div>
</body>
</html>
