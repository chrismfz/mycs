{{-- Live services on cPanel servers whose account their server does not list (Utilities → Hosting Accounts). --}}
@if ($services->isEmpty())
    <p class="text-sm text-gray-500">Every live cPanel service has its account on its server.</p>
@else
    <ul class="text-sm divide-y divide-gray-100 dark:divide-white/10">
        @foreach ($services as $service)
            <li class="py-1.5">
                <a class="text-primary-600 hover:underline" href="{{ \App\Admin\Resources\ServiceResource::getUrl('edit', ['record' => $service->id]) }}">#{{ $service->id }} {{ $service->product?->name }}</a>
                · {{ $service->client?->name }} · {{ $service->domain ?: '—' }}
                <span class="text-gray-500">· username {{ ($service->properties->firstWhere('key', 'cpanel_username')->value ?? $service->username) ?: '(none)' }} · {{ $service->status }}</span>
            </li>
        @endforeach
    </ul>
@endif
