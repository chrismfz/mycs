{{-- An article: staff's HTML, sanitized (KbArticle::safeBody), never compiled. --}}
<div class="container mt-10 mb-16 space-y-6">
    <x-navigation.breadcrumb :trail="[['name' => __('knowledgebase.title'), 'url' => route('kb.index')],
        ...($categories->isNotEmpty() ? [['name' => $categories->first()->localized('name'), 'url' => $categories->first()->url()]] : []),
        ['name' => $article->localized('title')]]" />
    <article class="bg-background-secondary border border-neutral rounded-lg p-6 space-y-4">
        <h1 class="text-3xl font-semibold">{{ $article->localized('title') }}</h1>
        <div class="prose dark:prose-invert max-w-none kb-article">{!! $article->safeBody() !!}</div>
    </article>
    @if (filled($article->tags))
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-base/60">{{ __('knowledgebase.tags') }}:</span>
        @foreach ($article->tags as $tag)
        <a href="{{ \App\Models\KbArticle::tagUrl($tag) }}" wire:navigate class="px-2 py-0.5 rounded bg-background border border-neutral hover:border-primary">{{ $tag }}</a>
        @endforeach
    </div>
    @endif
    @if ($categories->count() > 1)
    <p class="text-sm text-base/60">{{ __('knowledgebase.also_in') }}
        @foreach ($categories->slice(1) as $category)<a href="{{ $category->url() }}" wire:navigate class="underline">{{ $category->localized('name') }}</a>@if (!$loop->last), @endif @endforeach
    </p>
    @endif
    <p class="text-sm text-base/60">{{ __('knowledgebase.not_helped') }}
        <a href="{{ auth('web')->check() ? route('tickets.create') : route('contact') }}" class="underline">{{ __('knowledgebase.contact_us') }}</a>
    </p>
</div>
