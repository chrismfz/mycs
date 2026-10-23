<form method="GET" action="{{ route('kb.index') }}" role="search" class="flex gap-2">
    <input type="search" name="q" value="{{ $value ?? '' }}" maxlength="200" placeholder="{{ __('knowledgebase.search_placeholder') }}"
        aria-label="{{ __('knowledgebase.search') }}"
        class="flex-1 bg-background-secondary border border-neutral rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
    <x-button.primary type="submit" class="!w-auto">{{ __('knowledgebase.search') }}</x-button.primary>
</form>
