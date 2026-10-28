{{-- A product as a card, with its price, what it gives and the way to order: on its category's page and on «Show all». The heading an h2, or $heading where the page has its own above it. --}}
@php
    $plans = $product->availablePlans();
    $plan = $plans->first();
    $price = $product->price();
    $orderable = $product->stock !== 0 && $price->available;
    $others = $plans->slice(1)->map(fn ($other) => $other->cycle())->filter()->unique()->values();
@endphp
<div class="flex flex-col bg-background-secondary border border-neutral p-5 rounded-lg">
    @if ($product->image)
    <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}"
        class="mb-4 rounded-md {{ theme('small_images', false) ? 'w-14 h-fit' : 'aspect-video w-full object-cover object-center' }}">
    @endif
    <{{ $heading ?? 'h2' }} class="text-lg font-bold">{{ $product->name }}</{{ $heading ?? 'h2' }}>
    @if ($product->stock === 0)
    <span class="mt-1 w-fit rounded px-2 py-0.5 text-xs font-medium bg-red-900 text-red-300">{{ __('product.out_of_stock', ['product' => $product->name]) }}</span>
    @endif

    <div class="mt-3">
        <span class="text-2xl font-bold">{{ $price->formatted->price }}</span>
        {{-- A free price has no cycle (Product::price() is free when any plan is). --}}
        @if ($plan !== null && $price->available && !$price->is_free && filled($plan->perCycle()))
        <span class="text-sm text-base/60">{{ $plan->perCycle() }}</span>
        @endif
    </div>
    @if ($price->has_setup_fee)
    <p class="text-xs text-base/60">+ {{ $price->formatted->setup_fee }} {{ __('product.setup_fee') }}</p>
    @endif
    @if ($others->isNotEmpty())
    <p class="mt-1 text-xs text-base/60">{{ __('product.also_for', ['cycles' => $others->implode(', ')]) }}</p>
    @endif

    @if (filled(strip_tags((string) $product->description)))
    {{-- A long list of features: its first 6, the rest a click away (UI polish of 2026-10-20). --}}
    @php
        // Only one flat list (as WHMCS's): the CSS hides from the 7th of each list, so with more lists, or
        // nested ones, «+N» would count what is not hidden (review of #273).
        $html = strtolower((string) $product->description);
        $lists = preg_match_all('/<(ul|ol)\b/', $html);
        $features = $lists === 1 ? preg_match_all('/<li\b/', $html) : 0;
    @endphp
    <div x-data="{ all: false }" class="mt-4">
        <article x-ref="list" class="prose prose-sm dark:prose-invert {{ $features > 6 ? '[&_li:nth-of-type(n+7)]:hidden' : '' }}">
            {!! Str::sanitizeHtml((string) $product->description) !!}
        </article>
        @if ($features > 6)
        <button type="button" x-show="!all" x-on:click="all = true; $refs.list.classList.remove('[&_li:nth-of-type(n+7)]:hidden')" class="mt-1 text-sm text-secondary hover:underline">
            {{ __('product.more_features', ['count' => $features - 6]) }}
        </button>
        @endif
    </div>
    @endif

    <div class="mt-auto pt-5 flex flex-col gap-2">
        @if ($orderable)
        <a href="{{ route('products.checkout', ['category' => $product->category, 'product' => $product->slug]) }}" wire:navigate>
            <x-button.primary class="w-full">{{ __('product.order') }}</x-button.primary>
        </a>
        @endif
        {{-- «Direct Checkout» (Appearance): no product page, the order is the only way. --}}
        @if (!theme('direct_checkout', false) || !$orderable)
        <a href="{{ route('products.show', ['category' => $product->category, 'product' => $product->slug]) }}" wire:navigate
            class="text-center text-sm text-base/70 hover:text-secondary hover:underline">
            {{ __('product.details') }}
        </a>
        @endif
    </div>
</div>
