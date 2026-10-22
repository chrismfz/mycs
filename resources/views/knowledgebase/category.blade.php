<div class="container mt-10 mb-16 space-y-6">
    <x-navigation.breadcrumb :trail="[['name' => __('knowledgebase.title'), 'url' => route('kb.index')], ['name' => $category->localized('name')]]" />
    <div class="space-y-2">
        <h1 class="text-3xl font-semibold">{{ $category->localized('name') }}</h1>
        @if (filled($category->localized('description')))
        <p class="text-base/60">{{ $category->localized('description') }}</p>
        @endif
    </div>
    @include('knowledgebase.partials.search')
    @if ($articles->isEmpty())
    <p class="text-base/60">{{ __('knowledgebase.empty') }}</p>
    @else
    @include('knowledgebase.partials.list', ['articles' => $articles])
    @endif
</div>
