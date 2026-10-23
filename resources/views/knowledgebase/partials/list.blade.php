{{-- Articles as a list: title and a line of their text. --}}
<ul class="divide-y divide-neutral bg-background-secondary border border-neutral rounded-lg">
    @foreach ($articles as $item)
    <li class="p-4">
        <a href="{{ $item->url() }}" class="font-medium hover:text-primary" wire:navigate>{{ $item->localized('title') }}</a>
        <p class="text-sm text-base/60 mt-1">{{ $item->excerpt(180) }}</p>
    </li>
    @endforeach
</ul>
