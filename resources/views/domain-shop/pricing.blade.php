@php($money = fn (?string $amount) => $amount === null || $currency === null ? '—' : (new \App\Classes\Price(['price' => $amount, 'currency' => $currency]))->formatted->price)
<div class="container mt-14 space-y-6">
    <div class="text-center space-y-2">
        <h1 class="text-3xl font-semibold">{{ __('domains.shop.pricing_title') }}</h1>
        <p class="text-base/60">{{ __('domains.shop.prices_note') }}</p>
    </div>
    <div class="bg-background-secondary border border-neutral rounded-lg overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-base/60 border-b border-neutral">
                <tr>
                    <th class="px-4 py-3">TLD</th>
                    <th class="px-4 py-3">{{ __('domains.shop.years') }}</th>
                    <th class="px-4 py-3">{{ __('domains.shop.register') }}</th>
                    <th class="px-4 py-3">{{ __('domains.shop.renew') }}</th>
                    <th class="px-4 py-3">{{ __('domains.shop.transfer') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                @foreach ($row['years'] as $years => $p)
                <tr class="border-b border-neutral last:border-0">
                    <td class="px-4 py-2 font-medium">@if ($loop->first).{{ $row['tld'] }}@endif</td>
                    <td class="px-4 py-2">{{ $years }}</td>
                    <td class="px-4 py-2">{{ $money($p['register']) }}</td>
                    <td class="px-4 py-2">{{ $money($p['renew']) }}</td>
                    <td class="px-4 py-2">{{ $money($p['transfer']) }}</td>
                </tr>
                @endforeach
                @empty
                <tr><td colspan="5" class="px-4 py-6 text-base/60">{{ __('domains.shop.no_prices') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
