@if ($services->isEmpty())
<p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No services') }}</p>
@else
<ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
    @foreach ($services as $service)
    <li class="flex justify-between gap-3 py-1">
        <a @if ($u = $serviceUrl($service)) href="{{ $u }}" @endif class="min-w-0 truncate hover:underline">#{{ $service->id }} · {{ $service->product?->name }} · {{ $service->label }}</a>
        <span class="shrink-0 text-gray-500 dark:text-gray-400">
            @if ($service->client && $showClients)<a @if ($u = $clientUrl($service->client_id)) href="{{ $u }}" @endif class="hover:underline">{{ $service->client->name }}</a> · @endif
            {{ __('due :date', ['date' => $service->expires_at?->toDateString()]) }}
        </span>
    </li>
    @endforeach
</ul>
@endif
