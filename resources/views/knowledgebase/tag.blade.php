<div class="container mt-10 mb-16 space-y-6">
    <x-navigation.breadcrumb :trail="[['name' => __('knowledgebase.title'), 'url' => route('kb.index')], ['name' => __('knowledgebase.tag_title', ['tag' => $name])]]" />
    <h1 class="text-3xl font-semibold">{{ __('knowledgebase.tag_title', ['tag' => $name]) }}</h1>
    @include('knowledgebase.partials.list', ['articles' => $articles])
</div>
