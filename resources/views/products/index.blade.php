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
            {!! Str::sanitizeHtml((string) $category->description) !!}
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
                @include('products.partials.category-card', ['childCategory' => $childCategory])
                @endforeach
            </div>
            @endif

            @if (count($products) >= 1)
            <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach ($products as $product)
                @include('products.partials.product-card', ['product' => $product])
                @endforeach
            </div>
            @endif
        </div>
    </div>
</div>
