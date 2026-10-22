<div class="container mt-14">
    <x-navigation.breadcrumb :trail="[['name' => __('navigation.services'), 'url' => route('services')], ['name' => $service->label]]" />
    @if($invoice = $service->invoices()->where('status', 'unpaid')->first())
    <div class="w-full mb-4">
        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-900 dark:bg-yellow-600/20 dark:text-yellow-200 p-4 rounded-lg">
            <p class="font-medium">
                ⚠️ {{ __('services.outstanding_invoice') }}
                <a href="{{ route('invoices.show', $invoice)}}"
                    class="underline underline-offset-2 hover:text-yellow-700 dark:hover:text-yellow-100">{{ __('services.view_and_pay') }}</a>.
            </p>
        </div>
    </div>
    @endif
    <div class="bg-background-secondary border border-neutral p-6 rounded-lg mt-2">
        <div class="flex flex-col md:flex-row justify-between">
            <h1 class="text-2xl font-semibold">{{ __('services.services') }}</h1>
        </div>
        <div class="grid md:grid-cols-2 gap-4 my-4">
            <div>
                <h4 class="text-lg font-semibold">{{ __('services.product_details') }}:</h4>
                <div class="mt-2">
                    @include('services.partials.label')
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('services.price') }}:</span>
                        <span class="text-base/50">{{ $service->formattedPrice }}</span>
                    </div>
                    @if($service->plan->type == 'recurring')
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('services.billing_cycle') }}:</span>
                        <span class="text-base/50">{{ __('services.every_period', [
                            'period' => $service->plan->billing_period > 1 ? $service->plan->billing_period : '',
                            'unit' => trans_choice(__('services.billing_cycles.' . $service->plan->billing_unit),
                            $service->plan->billing_period)
                            ])
                            }}</span>
                    </div>
                    @if($service->expires_at)
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('services.renews_on') }}:</span>
                        <span class="text-base/50">
                            {{ $service->expires_at->format('M d, Y') }}
                        </span>
                    </div>
                    @endif
                    @endif
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('services.status') }}:</span>
                        @if($service->cancellation && $service->status == 'active')
                        <span class="font-semibold text-orange-500">
                            {{ __('services.statuses.cancellation_pending') }}
                        </span>
                        @else
                        <span
                            class="font-semibold @if ($service->status == 'active') text-green-500 @elseif($service->isEnded()) text-red-500  @else text-orange-500 @endif">
                            {{ __('services.statuses.' . $service->status) }}
                        </span>
                        @endif
                    </div>
                    @if($service->status == 'suspended' && filled($service->suspend_reason))
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('services.suspend_reason') }}:</span>
                        {{-- The cron's (WHMCS's) reason in the customer's language; staff's own as written (review F4). --}}
                        <span class="text-base/50">{{ $service->suspend_reason === 'Overdue on Payment' ? __('services.suspend_reasons.overdue') : $service->suspend_reason }}</span>
                    </div>
                    @endif
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('services.registration_date') }}:</span>
                        <span class="text-base/50">{{ $service->created_at?->format('M d, Y') }}</span>
                    </div>
                    @include('services.partials.account')
                    @include('services.partials.license')
                    @include('services.partials.contact')
                    @can('update', $service)
                    @include('services.partials.billing-agreement')
                    @endcan
                    <br>
                    @foreach ($fields as $field)
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ $field['label'] }}:</span>
                        <span class="text-base/50">{{ $field['text'] }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @if((Gate::allows('update', $service) && ($service->cancellable || $service->upgradable || \App\Services\ServiceUpgrade\ChangeCycle::plans($service)->isNotEmpty())) || count($buttons) > 0)
            <div>
                <h4 class="text-lg font-semibold">{{ __('services.actions') }}:</h4>
                <div class="mt-2 flex flex-row gap-2 flex-wrap">
                    @can('update', $service)
                    @if($service->upgradable)
                    <a href="{{ route('services.upgrade', $service->id) }}">
                        <x-button.primary class="h-fit !w-fit">
                            <span>{{ __('services.upgrade') }}</span>
                        </x-button.primary>
                    </a>
                    @endif
                    @if($service->upgrade()->where('status', 'pending')->exists())
                    <x-button.primary class="h-fit !w-fit"
                        @click="Alpine.store('notifications').addNotification([{message: '{{ __('services.upgrade_pending') }}', type: 'error'}])">
                        <span>{{ __('services.upgrade') }}</span>
                    </x-button.primary>
                    @endif
                    @php($cyclePlans = \App\Services\ServiceUpgrade\ChangeCycle::plans($service))
                    @if($cyclePlans->isNotEmpty() && \App\Services\ServiceUpgrade\ChangeCycle::refusal($service, $cyclePlans->first(), 'next_renewal') === null)
                    <x-button.secondary class="h-fit !w-fit" wire:click="$set('showCycle', true)">
                        <span>{{ __('services.change_cycle') }}</span>
                    </x-button.secondary>
                    @endif
                    @if($showCycle)
                    <x-modal open="true" title="{{ __('services.change_cycle') }}" width="max-w-xl">
                        <form wire:submit="changeCycle" class="flex flex-col gap-4">
                            <p class="text-base/70">{{ __('services.change_cycle_note') }}</p>
                            @if($service->nextPlan)
                            <p class="text-base/70">{{ __('services.change_cycle_pending', ['plan' => $service->nextPlan->name]) }}</p>
                            @endif
                            <x-form.select name="cyclePlan" wire:model="cyclePlan" :label="__('services.billing_cycle')" required>
                                <option value="">{{ __('Choose') }}</option>
                                @foreach($cyclePlans as $cyclePlanOption)
                                <option value="{{ $cyclePlanOption->id }}">{{ $cyclePlanOption->name }}@if($cyclePlanOption->id === $service->plan_id) ({{ __('services.change_cycle_keep') }})@else ({{ \App\Services\ServiceUpgrade\ChangeCycle::quote($service, $cyclePlanOption, 'next_renewal')['gross'] }} {{ $service->currency_code }})@endif</option>
                                @endforeach
                            </x-form.select>
                            <x-button.primary type="submit" class="!w-fit">{{ __('services.change_cycle') }}</x-button.primary>
                        </form>
                        <x-slot name="closeTrigger">
                            <button wire:click="$set('showCycle', false)" @click="open = false" class="text-primary-100">
                                <x-ri-close-fill class="size-6" />
                            </button>
                        </x-slot>
                    </x-modal>
                    @endif
                    @if($service->cancellable)
                    <x-button.danger class="h-fit !w-fit" wire:click="$set('showCancel', true)">
                        <span wire:loading.remove wire:target="$set('showCancel', true)">{{ __('services.cancel')
                            }}</span>
                        <x-loading target="$set('showCancel', true)" />
                    </x-button.danger>
                    @endif
                    @endcan
                    @if($showCancel)
                    <x-modal open="true"
                        title="{{ __('services.cancellation', ['service' => $service->product->name]) }}"
                        width="max-w-3xl">
                        <livewire:services.cancel :service="$service" />
                        <x-slot name="closeTrigger">
                            <div class="flex gap-4">
                                <button wire:click="$set('showCancel', false)" @click="open = false"
                                    class="text-primary-100">
                                    <x-ri-close-fill class="size-6" />
                                </button>
                            </div>
                        </x-slot>
                    </x-modal>
                    @endif
                </div>
                <div class="mt-2 flex flex-row gap-2 flex-wrap">
                    @foreach ($buttons as $button)
                    <!-- If the button has a function then call it when clicked -->
                    @if (isset($button['function']))
                    <x-button.primary class="h-fit !w-fit" wire:click="goto('{{ $button['function'] }}')">
                        {{ $button['label'] }}
                    </x-button.primary>
                    @else
                    <a href="{{ $button['url'] }}"
                        @if(!empty($button['target'])) target="{{ $button['target'] }}" @endif
                        @if(($button['target'] ?? null) === '_blank') rel="noopener noreferrer" @endif>
                        <x-button.primary class="h-fit !w-fit">
                            {{ $button['label'] }}
                        </x-button.primary>
                    </a>
                    @endif
                    @endforeach
                </div>
            </div>
            @endif
        </div>
    </div>

    @include('services.partials.usage')
    @include('services.partials.panel-tools')

    @if (count($views) > 0)
    <div class="bg-primary-800 rounded-lg mt-2">
        @if (count($views) > 1)
        <div class="flex w-fit mb-2 flex-row flex-wrap">
            @foreach ($views as $view)
            <button wire:click="changeView('{{ $view['name'] }}')"
                class="px-4 py-2 -mb-px focus:outline-none {{ $view['name'] == $currentView ? 'border-b-2 border-gray-400 font-semibold' : 'text-base border-b border-gray-500 ' }}">
                {{ $view['label'] }}
            </button>
            @endforeach
        </div>
        @endif

        <!-- show loading spinner -->
        <x-loading target="changeView" />
        <div wire:loading.remove wire:target="changeView">
            {!! $extensionView !!}
        </div>
    </div>
    @endif
</div>