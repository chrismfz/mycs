{{-- The knowledgebase (docs/knowledgebase.md): search, categories, the most read. --}}
<div class="container mt-10 mb-16 space-y-8">
    <div class="text-center space-y-2">
        <h1 class="text-3xl font-semibold">{{ __('knowledgebase.title') }}</h1>
        <p class="text-base/60">{{ __('knowledgebase.description') }}</p>
    </div>
    <div class="max-w-2xl mx-auto">
        <form wire:submit.prevent class="flex gap-2" role="search">
            <input type="search" wire:model.live.debounce.400ms="search" maxlength="200" placeholder="{{ __('knowledgebase.search_placeholder') }}"
                aria-label="{{ __('knowledgebase.search') }}"
                class="flex-1 bg-background-secondary border border-neutral rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
        </form>
    </div>

    @if ($search !== '')
    <section class="space-y-3">
        <h2 class="text-xl font-semibold">{{ __('knowledgebase.results', ['search' => $search]) }}</h2>
        @if ($results->isEmpty())
        <p class="text-base/60">{{ __('knowledgebase.no_results') }}</p>
        @else
        @include('knowledgebase.partials.list', ['articles' => $results])
        @endif
    </section>
    @else
    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($categories as $category)
        <a href="{{ $category->url() }}" wire:navigate class="block bg-background-secondary border border-neutral rounded-lg p-4 hover:border-primary">
            <div class="flex items-center justify-between gap-2">
                <span class="font-semibold">{{ $category->localized('name') }}</span>
                <span class="text-xs text-base/60">{{ trans_choice('knowledgebase.articles', $category->articles_count) }}</span>
            </div>
            @if (filled($category->localized('description')))
            <p class="text-sm text-base/60 mt-2">{{ $category->localized('description') }}</p>
            @endif
        </a>
        @endforeach
    </section>
    @if ($popular->isNotEmpty())
    <section class="space-y-3">
        <h2 class="text-xl font-semibold">{{ __('knowledgebase.popular') }}</h2>
        @include('knowledgebase.partials.list', ['articles' => $popular])
    </section>
    @endif
    @endif
</div>
