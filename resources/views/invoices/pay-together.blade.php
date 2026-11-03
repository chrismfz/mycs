<div class="container mt-14 space-y-4">
    <x-navigation.breadcrumb :trail="[['name' => __('navigation.invoices'), 'url' => route('invoices')], ['name' => __('invoices.mass_pay_title')]]" />

    @if ($pay)
    <x-modal :title="__('invoices.mass_pay_title')" open>
        <x-slot name="closeTrigger">
            <div class="flex gap-4">
                {{ __('invoices.amount_due', ['amount' => number_format((float) $paymentGroup->total, 2) . ' ' . $paymentGroup->currency_code]) }}
                <button wire:confirm="{{ __('invoices.close_payment_confirm') }}" wire:click="exitPay" @click="open = false" class="text-primary-100">
                    <x-ri-close-fill class="size-6" />
                </button>
            </div>
        </x-slot>
        <div class="mt-8">{{ $pay }}</div>
    </x-modal>
    @endif

    {{-- The group's invoices beside the ways to pay (docs/pay-flow-ux.md, PR 4); under them on a phone. --}}
    @php($payable = !$this->held && !$this->staleness)
    <div class="grid gap-4 items-start {{ $payable ? 'xl:grid-cols-[minmax(0,1fr)_22rem]' : '' }}">
    <div class="bg-background-secondary border border-neutral p-6 rounded-lg min-w-0">
        <h1 class="text-2xl font-bold">{{ __('invoices.mass_pay_title') }}</h1>
        <p class="text-base text-sm mt-2">{{ __('invoices.mass_pay_explain') }}</p>

        <ol class="mt-6 divide-y divide-neutral">
            @foreach ($paymentGroup->invoices as $invoice)
            <li class="flex justify-between gap-4 py-2">
                <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="underline min-w-0">
                    {{ $invoice->displayTitle() }}
                    @if ($invoice->due_at)
                    <span class="text-base/50 text-sm">({{ __('invoices.due_date') }}: {{ $invoice->due_at->format('d M Y') }})</span>
                    @endif
                </a>
                <span class="whitespace-nowrap">{{ number_format((float) $invoice->pivot->amount, 2) }} {{ $paymentGroup->currency_code }}</span>
            </li>
            @endforeach
        </ol>
        <div class="flex justify-between py-2 mt-1 border-t border-neutral font-semibold">
            <span>{{ __('invoices.total') }}</span>
            <span>{{ number_format((float) $paymentGroup->total, 2) }} {{ $paymentGroup->currency_code }}</span>
        </div>
    </div>

    @if ($this->held)
    {{-- A payment of these invoices on its way: no other is made meanwhile (SavedMethodCharge::onItsWay), not «changed». --}}
    <div role="status" class="border border-warning bg-warning/10 rounded-lg p-6 space-y-2">
        <p class="text-lg font-semibold text-warning">{{ __('invoices.mass_pay_held_title') }}</p>
        <p class="text-sm">{{ __('invoices.mass_pay_held_body') }}</p>
        <a href="{{ route('invoices') }}" wire:navigate class="text-sm text-primary underline">{{ __('invoices.invoices') }}</a>
    </div>
    @elseif ($this->staleness)
    <div role="status" class="border border-warning bg-warning/10 rounded-lg p-6 space-y-2">
        <p class="text-warning font-semibold">{{ $this->staleness }}</p>
        <a href="{{ route('invoices') }}" wire:navigate class="text-sm text-primary underline">{{ __('invoices.invoices') }}</a>
    </div>
    @else
    <section aria-labelledby="pay-title" class="xl:order-last">
        @include('invoices.partials.pay-panel', [
            'payAmount' => number_format((float) $paymentGroup->total, 2) . ' ' . $paymentGroup->currency_code,
            'payGroups' => $this->payGroups,
            'payLabel' => $this->payLabel,
            'automaticMethodId' => $this->automaticMethodId,
            'payNote' => __('invoices.mass_pay_not_here'),
        ])
    </section>
    @endif
    </div>
</div>
