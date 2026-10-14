<div class="container mt-14">
    <x-navigation.breadcrumb />
    <div class="px-2">
        <h4 class="text-2xl font-bold pb-3">{{ __('account.credits') }}</h4>
        @if (Auth::user()->credits->count() > 0)
        <div class="flex flex-wrap gap-4">
            @foreach (Auth::user()->credits as $credit)
            <div class="flex flex-col bg-background-secondary w-fit rounded-lg px-5 p-3 items-center gap-1">
                <h5 class="text-lg font-bold">{{ $credit->currency->code }}</h5>
                <p class="text-primary-100">{{ $credit->formattedAmount }}</p>
            </div>
            @endforeach
        </div>
        @else
        <p>{{ __('account.no_credit') }}</p>
        @endif

        <div class="py-4">
            <x-form.toggle :label="__('account.credit_auto_apply')" wire:model.live="autoApply" />
            <p class="text-sm text-base/60 mt-1">{{ __('account.credit_auto_apply_help') }}</p>
        </div>

        <h4 class="text-xl font-bold pb-3">{{ __('account.add_credit') }}</h4>

        <form wire:submit.prevent="addCredit">
            <!-- Currency and amount -->
            <div class="grid grid-cols-2 gap-4">
                <x-form.select name="currency" :label="__('account.input.currency')" wire:model.live="currency" required>
                    @foreach(\App\Models\Currency::all() as $currency)
                    <option value="{{ $currency->code }}">{{ $currency->code }}</option>
                    @endforeach
                </x-form.select>
                <x-form.input x-mask:dynamic="$money($input, '.', '', 2)" name="amount" type="number"
                    :label="__('account.input.amount')" :placeholder="__('account.input.amount_placeholder')"
                    wire:model.live.debounce.250ms="amount" required />

                <x-form.select name="gateway" :label="__('product.payment_method')" wire:model.live="gateway" required>
                    @foreach($gateways as $gatewayy)
                    <option value="{{ $gatewayy->id }}" wire:key="{{ $gatewayy->id }}" @if($gatewayy->id == $gateway) selected @endif>{{ $gatewayy->name }}</option>
                    @endforeach
                </x-form.select>
            </div>


            <x-button.primary type="submit" class="w-full mt-4">
                {{ __('account.add_credit') }}
            </x-button.primary>
        </form>

        <h4 class="text-xl font-bold pt-8 pb-3">{{ __('account.credit_history') }}</h4>
        @if ($history->isEmpty())
        <p>{{ __('account.credit_history_empty') }}</p>
        @else
        <div class="overflow-x-auto bg-background-secondary border border-neutral rounded-lg">
            <table class="w-full text-sm text-left">
                <thead class="text-base/60">
                    <tr>
                        <th class="px-4 py-2">{{ __('account.credit_history_date') }}</th>
                        <th class="px-4 py-2">{{ __('account.credit_history_movement') }}</th>
                        <th class="px-4 py-2">{{ __('account.credit_history_invoice') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('account.credit_history_amount') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('account.credit_history_balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($history as $event)
                    <tr class="border-t border-neutral" wire:key="credit-event-{{ $event->id }}">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $event->occurred_at?->translatedFormat('d M Y H:i') }}</td>
                        <td class="px-4 py-2">{{ \App\Services\Money\MoneyHistory::customerLabel($event) }}</td>
                        <td class="px-4 py-2">
                            @if ($event->invoice && (int) $event->invoice->user_id === (int) Auth::id())
                            <a href="{{ route('invoices.show', $event->invoice) }}" class="text-primary hover:underline" wire:navigate>#{{ $event->invoice_id }}</a>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap {{ $event->creditEffectCents() < 0 ? 'text-error' : 'text-success' }}">{{ \App\Services\Money\MoneyHistory::signedCredit($event) }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">{{ \App\Services\Money\MoneyHistory::money($event->credit_balance_after, $event->currency_code) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="pt-3">{{ $history->links() }}</div>
        @endif
    </div>
</div>