{{-- «Contact» without a login (docs/tickets.md §3 Ζ): a guest's ticket, its number by mail. --}}
<div class="container mt-14 max-w-3xl">
    <div class="bg-primary-800 p-6 rounded-lg">
        <h1 class="text-2xl font-semibold mb-2">{{ __('navigation.contact') }}</h1>
        @if ($sent)
            <div role="status" class="py-6">
                <p class="text-lg font-semibold">{{ __('ticket.contact_sent_title') }}</p>
                <p class="mt-2 text-base/80">{{ __('ticket.contact_sent_body') }}</p>
                <p class="mt-4 text-sm">{!! __('ticket.contact_login', ['login' => '<a class="underline" href="' . e(route('login')) . '">' . e(__('auth.sign_in')) . '</a>']) !!}</p>
            </div>
        @elseif (count($departments) === 0)
            <p class="py-6">{!! __('ticket.contact_unavailable', ['login' => '<a class="underline" href="' . e(route('login')) . '">' . e(__('auth.sign_in')) . '</a>']) !!}</p>
        @else
            <p class="mb-4 text-sm text-base/80">{!! __('ticket.contact_intro', ['login' => '<a class="underline" href="' . e(route('login')) . '">' . e(__('auth.sign_in')) . '</a>']) !!}</p>
            <form wire:submit="submit" id="contact" class="flex flex-col gap-4">
                <div class="grid md:grid-cols-2 gap-4">
                    <x-form.input wire:model="name" name="name" :label="__('ticket.contact_name')" autocomplete="name" maxlength="100" required />
                    <x-form.input wire:model="email" name="email" type="email" :label="__('general.input.email')" autocomplete="email" maxlength="191" required />
                </div>
                <x-form.input wire:model="subject" name="subject" :label="__('ticket.subject')" maxlength="200" required />
                @if (count($departments) > 1)
                    <x-form.select wire:model="department" name="department" :label="__('ticket.department')" required>
                        <option value="">{{ __('ticket.select_department') }}</option>
                        @foreach ($departments as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </x-form.select>
                @endif
                <x-form.textarea wire:model="message" name="message" :label="__('ticket.contact_message')" rows="8" maxlength="10000" required />
                {{-- A field for bots: out of sight and out of reach of people and screen readers. --}}
                <div class="absolute -left-[10000px] top-auto h-px w-px overflow-hidden" aria-hidden="true">
                    <label for="website">Website</label>
                    <input type="text" id="website" name="website" wire:model="website" tabindex="-1" autocomplete="off" />
                </div>
                {{-- The chosen captcha, or ours when none is (Captchable::requireCaptchaOrAltcha). --}}
                @if (\App\Livewire\Contact::captchaKind() === 'altcha' && config('settings.captcha') !== 'altcha')
                    <div class="flex flex-col justify-center mt-2">
                        <div wire:ignore><x-captcha.altcha /></div>
                        @error('captcha')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                @else
                    <x-captcha :form="'contact'" />
                @endif
                <x-button.primary type="submit" class="w-full md:w-auto md:self-end" wire:loading.attr="disabled">{{ __('ticket.contact_send') }}</x-button.primary>
            </form>
        @endif
    </div>
</div>
