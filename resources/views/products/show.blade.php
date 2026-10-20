{{-- A product (polish of 2026-10-20): the way back, its price per cycle, what it gives, one way to order. --}}
@php
    $plans = $product->availablePlans();
    $plan = $plans->first();
    $price = $product->price();
    $orderable = $product->stock !== 0 && $price->available;
    $others = $plans->slice(1)->map(fn ($other) => $other->cycle())->filter()->unique()->values();
@endphp
<div class="container mt-10 mb-16">
    <x-navigation.breadcrumb :trail="[['name' => $category->name, 'url' => route('category.show', ['category' => $category->slug])], ['name' => $product->name]]" />

    <div class="mt-4 flex flex-col @if ($product->image) md:grid md:grid-cols-2 md:gap-12 @endif bg-background-secondary border border-neutral p-6 rounded-lg">
        @if ($product->image)
        <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}"
            class="w-full h-80 object-contain object-center rounded-md">
        @endif
        <div class="flex flex-col max-w-2xl">
            @if ($product->stock === 0)
            <span class="text-xs font-medium px-2.5 py-0.5 rounded bg-red-900 text-red-300 w-fit mb-3">
                {{ __('product.out_of_stock', ['product' => $product->name]) }}
            </span>
            @elseif ($product->stock > 0)
            <span class="text-xs font-medium px-2.5 py-0.5 rounded bg-green-900 text-green-300 w-fit mb-3">
                {{ __('product.in_stock') }}
            </span>
            @endif
            <h1 class="text-3xl font-bold">{{ $product->name }}</h1>
            <div class="mt-3">
                <span class="text-2xl font-bold">{{ $price->formatted->price }}</span>
                @if ($plan !== null && $price->available && filled($plan->cycle()))
                <span class="text-base/60">/ {{ $plan->cycle() }}</span>
                @endif
            </div>
            @if ($price->has_setup_fee)
            <p class="text-sm text-base/60">+ {{ $price->formatted->setup_fee }} {{ __('product.setup_fee') }}</p>
            @endif
            @if ($others->isNotEmpty())
            <p class="mt-1 text-sm text-base/60">{{ __('product.also_for', ['cycles' => $others->implode(', ')]) }}</p>
            @endif

            @if (filled(strip_tags((string) $product->description)))
            <article class="my-6 prose dark:prose-invert">
                {!! $product->description !!}
            </article>
            @endif

            @if ($orderable)
            <a href="{{ route('products.checkout', ['category' => $category, 'product' => $product->slug]) }}" wire:navigate class="mt-auto sm:w-fit">
                <x-button.primary class="w-full sm:w-64">{{ __('product.order') }}</x-button.primary>
            </a>
            @endif
        </div>
    </div>
</div>
