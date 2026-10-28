{{-- A subcategory as a card: on its parent's page and on «Show all». The heading an h2, or $heading where the page has its own above it. --}}
<a href="{{ route('category.show', ['category' => $childCategory->slug]) }}" wire:navigate
    class="group flex flex-col bg-background-secondary border border-neutral hover:border-secondary p-5 rounded-lg transition-colors">
    @if ($childCategory->image)
    <img src="{{ Storage::url($childCategory->image) }}" alt="{{ $childCategory->name }}"
        class="mb-3 rounded-md {{ theme('small_images', false) ? 'w-14 h-fit' : 'aspect-video w-full object-cover object-center' }}">
    @endif
    <{{ $heading ?? 'h2' }} class="text-lg font-bold group-hover:text-secondary">{{ $childCategory->name }}</{{ $heading ?? 'h2' }}>
    @if (theme('show_category_description', true) && filled(strip_tags((string) $childCategory->description)))
    <article class="mt-2 prose prose-sm dark:prose-invert">
        {!! Str::sanitizeHtml((string) $childCategory->description) !!}
    </article>
    @endif
    <span class="mt-auto pt-4 text-sm font-semibold text-secondary">{{ __('common.button.view') }} →</span>
</a>
