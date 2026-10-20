{{--
    A category of the shop (polish of 2026-10-20): its name over its products, not over the menu; the categories as a
    menu with the open one marked (a row to scroll on a phone); each product with its price per cycle, what it gives,
    one way to order and one to read more.
--}}
@php
    $trail = [];
    for ($parent = $category->parent; $parent !== null && count($trail) < 5; $parent = $parent->parent) {
        array_unshift($trail, ['name' => $parent->name, 'url' => route('category.show', ['category' => $parent->slug])]);
    }
    $trail[] = ['name' => $category->name];
    // The open category and every one above it: a subcategory's page marks its root in the menu (review of #268).
    $chain = [$category->id];
    for ($parent = $category->parent; $parent !== null && count($chain) < 6; $parent = $parent->parent) {
        $chain[] = $parent->id;
    }
@endphp
<div class="container mt-10 mb-16">
    <x-navigation.breadcrumb :trail="$trail" />

    <header class="mt-4 mb-8 max-w-3xl">
        <h1 class="text-3xl md:text-4xl font-bold">{{ $category->name }}</h1>
        @if (filled(strip_tags((string) $category->description)))
        <article class="mt-3 prose dark:prose-invert text-base/80">
            {!! $category->description !!}
        </article>
        @endif
    </header>

    <div class="flex flex-col lg:flex-row gap-8">
        @if (count($categories) > 1)
        <nav aria-label="{{ __('product.categories') }}" class="lg:w-60 shrink-0">
            <h2 class="hidden lg:block mb-2 px-3 text-xs font-semibold uppercase tracking-wide text-base/60">{{ __('product.categories') }}</h2>
            <ul class="flex lg:flex-col gap-2 lg:gap-1 overflow-x-auto pb-2 lg:pb-0 -mx-1 px-1">
                @foreach ($categories as $ccategory)
                @php
                    $open = in_array($ccategory->id, $chain);
                @endphp
                <li class="shrink-0">
                    <a href="{{ route('category.show', ['category' => $ccategory->slug]) }}" wire:navigate
                        @if ($open) aria-current="{{ $ccategory->id == $category->id ? 'page' : 'true' }}" @endif
                        @class([
                            'block whitespace-nowrap rounded-md border px-3 py-2 text-sm transition-colors',
                            'border-secondary bg-secondary/10 text-secondary font-semibold' => $open,
                            'border-neutral lg:border-transparent hover:bg-background-secondary' => !$open,
                        ])>
                        {{ $ccategory->name }}
                    </a>
                </li>
                @endforeach
            </ul>
        </nav>
        @endif

        <div class="flex-1 min-w-0 flex flex-col gap-8">
            @if (count($childCategories) >= 1)
            <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach ($childCategories as $childCategory)
                <a href="{{ route('category.show', ['category' => $childCategory->slug]) }}" wire:navigate
                    class="group flex flex-col bg-background-secondary border border-neutral hover:border-secondary p-5 rounded-lg transition-colors">
                    @if ($childCategory->image)
                    <img src="{{ Storage::url($childCategory->image) }}" alt="{{ $childCategory->name }}"
                        class="mb-3 rounded-md {{ theme('small_images', false) ? 'w-14 h-fit' : 'aspect-video w-full object-cover object-center' }}">
                    @endif
                    <h2 class="text-lg font-bold group-hover:text-secondary">{{ $childCategory->name }}</h2>
                    @if (theme('show_category_description', true) && filled(strip_tags((string) $childCategory->description)))
                    <article class="mt-2 prose prose-sm dark:prose-invert">
                        {!! $childCategory->description !!}
                    </article>
                    @endif
                    <span class="mt-auto pt-4 text-sm font-semibold text-secondary">{{ __('common.button.view') }} →</span>
                </a>
                @endforeach
            </div>
            @endif

            @if (count($products) >= 1)
            <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach ($products as $product)
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
                    <h2 class="text-lg font-bold">{{ $product->name }}</h2>
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
                    <article class="mt-4 prose prose-sm dark:prose-invert">
                        {!! $product->description !!}
                    </article>
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
                @endforeach
            </div>
            @endif
        </div>
    </div>
</div>
