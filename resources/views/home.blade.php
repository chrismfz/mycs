<div>
    <div class="flex flex-col gap-6">
        <div class="w-full bg-background-secondary px-6 py-10 md:py-14 border-b border-neutral">
            <div class="container flex flex-col items-center gap-6 text-center">
                <article class="prose dark:prose-invert max-w-2xl">
                    {!! Str::markdown(theme('home_page_text', 'Welcome!'), [
                    'allow_unsafe_links' => false,
                    'renderer' => [
                    'soft_break' => "<br>"
                    ]]) !!}
                </article>

                @if ($domains)
                <form method="GET" action="{{ route('domain.search') }}" class="w-full max-w-xl flex flex-col gap-2">
                    <label for="home-domain" class="text-sm font-semibold">{{ __('domains.shop.title') }}</label>
                    <div class="flex gap-2">
                        <input id="home-domain" type="search" name="domain" maxlength="100" required
                            placeholder="{{ __('domains.shop.placeholder') }}"
                            class="flex-grow bg-background border border-neutral rounded-lg px-4 py-3" />
                        <x-button.primary type="submit" class="!w-fit px-6">{{ __('domains.shop.search') }}</x-button.primary>
                    </div>
                    <a href="{{ route('domain.pricing') }}" wire:navigate class="text-sm text-primary self-end">{{ __('home.all_domain_prices') }}</a>
                </form>
                @endif
            </div>
        </div>

        <div class="container mb-6 grid gap-6 md:grid-cols-3">
            @if ($categories->isNotEmpty())
            <div class="md:col-span-2 flex flex-col">
                <h2 class="text-lg font-semibold mb-2">{{ __('home.what_to_buy') }}</h2>
                @foreach ($categories as $category)
                @php $from = $category->startingPrice(); @endphp
                <a href="{{ route('category.show', ['category' => $category->slug]) }}" wire:navigate
                    class="flex items-center justify-between gap-4 py-3 border-t border-neutral hover:text-primary">
                    <span class="font-medium">{{ $category->name }}</span>
                    <span class="flex items-center gap-1 text-sm text-base/70 text-right">
                        @if ($from)
                        {{ __('home.from', ['price' => $from['price']]) }}@if ($from['cycle']) {{ $from['cycle'] }}@endif
                        @if ($from['monthly'])<span class="hidden sm:inline">({{ __('home.per_month', ['price' => $from['monthly']]) }})</span>@endif
                        @endif
                        <x-ri-arrow-right-s-line class="size-5" />
                    </span>
                </a>
                @endforeach
            </div>
            @endif

            @guest('web')
            <div class="flex flex-col gap-3 bg-background-secondary border border-neutral rounded-lg p-5 h-fit">
                <h2 class="text-lg font-semibold">{{ __('home.customer_title') }}</h2>
                <p class="text-sm text-base/70">{{ __('home.customer_lead') }}</p>
                <a href="{{ route('login') }}" wire:navigate><x-button.primary>{{ __('navigation.login') }}</x-button.primary></a>
                @if (!config('settings.registration_disabled', false))
                <a href="{{ route('register') }}" wire:navigate class="text-sm text-center text-primary">{{ __('home.new_customer') }}</a>
                @endif
            </div>
            @endguest
        </div>
    </div>
    {!! hook('pages.home') !!}
</div>
