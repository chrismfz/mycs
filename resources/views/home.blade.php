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
            @if ($categories->isNotEmpty() || in_array(true, $domainCards, true))
            <section class="md:col-span-2 flex flex-col gap-3" aria-labelledby="home-browse">
                <div class="flex items-baseline justify-between gap-4">
                    <h2 id="home-browse" class="text-lg font-semibold">{{ __('home.browse_title') }}</h2>
                    @if ($categories->isNotEmpty())
                    <a href="{{ route('products.all') }}" wire:navigate class="text-sm text-primary">{{ __('navigation.show_all') }}</a>
                    @endif
                </div>
                {{-- Each card's way in, a link drawn as a button (a button inside a link is not valid HTML). --}}
                @php $button = 'block w-full rounded-md border border-neutral bg-background px-4 py-2 text-center text-sm font-semibold transition-colors hover:border-primary hover:text-primary'; @endphp
                <div class="grid sm:grid-cols-2 gap-4">
                    @foreach ($categories as $category)
                    @php
                        $from = $category->startingPrice();
                        // A short line of its description (its paragraphs and items apart by a space): the card is a way in, the whole
                        // text is on its page.
                        $lead = theme('show_category_description', true)
                            ? Str::limit(Str::squish(html_entity_decode(strip_tags(str_replace('<', ' <', (string) $category->description)), ENT_QUOTES | ENT_HTML5)), 140)
                            : '';
                    @endphp
                    <div class="flex flex-col justify-between gap-4 bg-background-secondary border border-neutral rounded-lg p-5">
                        <div class="flex flex-col gap-2">
                            <h3 id="home-category-{{ $category->id }}" class="text-lg font-bold">{{ $category->name }}</h3>
                            @if ($lead !== '')
                            <p class="text-sm text-base/70">{{ $lead }}</p>
                            @endif
                            @if ($from)
                            <p class="text-sm text-base/70">
                                {{ __('home.from', ['price' => $from['price']]) }}@if ($from['cycle']) {{ $from['cycle'] }}@endif
                                @if ($from['monthly'])<span class="hidden sm:inline">({{ __('home.per_month', ['price' => $from['monthly']]) }})</span>@endif
                            </p>
                            @endif
                        </div>
                        <a href="{{ route('category.show', ['category' => $category->slug]) }}" wire:navigate
                            aria-describedby="home-category-{{ $category->id }}" class="{{ $button }}">{{ __('home.browse') }}</a>
                    </div>
                    @endforeach

                    @if ($domainCards['register'])
                    <div class="flex flex-col justify-between gap-4 bg-background-secondary border border-neutral rounded-lg p-5">
                        <div class="flex flex-col gap-2">
                            <h3 class="text-lg font-bold">{{ __('home.domain_register_title') }}</h3>
                            <p class="text-sm text-base/70">{{ __('home.domain_register_lead') }}</p>
                        </div>
                        <a href="{{ route('domain.search') }}" wire:navigate class="{{ $button }}">{{ __('home.domain_register_button') }}</a>
                    </div>
                    @endif
                    @if ($domainCards['transfer'])
                    <div class="flex flex-col justify-between gap-4 bg-background-secondary border border-neutral rounded-lg p-5">
                        <div class="flex flex-col gap-2">
                            <h3 class="text-lg font-bold">{{ __('home.domain_transfer_title') }}</h3>
                            <p class="text-sm text-base/70">{{ __('home.domain_transfer_lead') }}</p>
                        </div>
                        <a href="{{ route('domain.transfer') }}" wire:navigate class="{{ $button }}">{{ __('home.domain_transfer_button') }}</a>
                    </div>
                    @endif
                </div>
            </section>
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
