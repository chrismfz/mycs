{{--
    «Show all» of the shop (WHMCS's «Εμφάνιση όλων»): each category of the menu as a section, its name a link to its own
    page, then its subcategories and products in the cards of that page.
--}}
<div class="container mt-10 mb-16">
    <x-navigation.breadcrumb :trail="[['name' => __('product.all_title')]]" />

    <header class="mt-4 mb-8 max-w-3xl">
        <h1 class="text-3xl md:text-4xl font-bold">{{ __('product.all_title') }}</h1>
    </header>

    <div class="flex flex-col gap-12">
        @forelse ($categories as $category)
        <section aria-labelledby="category-{{ $category->id }}" class="flex flex-col gap-4">
            <div class="max-w-3xl">
                <h2 id="category-{{ $category->id }}" class="text-2xl font-bold">
                    <a href="{{ route('category.show', ['category' => $category->slug]) }}" wire:navigate class="hover:text-secondary">{{ $category->name }}</a>
                </h2>
                @if (theme('show_category_description', true) && filled(strip_tags((string) $category->description)))
                <article class="mt-2 prose dark:prose-invert text-base/80">
                    {!! Str::sanitizeHtml((string) $category->description) !!}
                </article>
                @endif
            </div>

            @if ($category->children->isNotEmpty())
            <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach ($category->children as $childCategory)
                @include('products.partials.category-card', ['childCategory' => $childCategory, 'heading' => 'h3'])
                @endforeach
            </div>
            @endif

            @if ($category->products->isNotEmpty())
            <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach ($category->products as $product)
                @include('products.partials.product-card', ['product' => $product, 'heading' => 'h3'])
                @endforeach
            </div>
            @endif
        </section>
        @empty
        <p class="text-base/70">{{ __('product.all_empty') }}</p>
        @endforelse
    </div>
</div>
