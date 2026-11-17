{{-- The signed form to the Cardlink vPOS (PR H2a): sent at once; the button is there if the script is not run.
     Sent by Alpine's x-init on the form itself, not a Livewire @script: the form comes with a later render (the «Pay»
     action), and Livewire 4 runs that render's scripts before it puts its HTML on the page, so a @script found no form
     and the customer had to click «continue» (2026-11-17, the same bug class as #893). x-init runs once the form is on
     the page, on every pay attempt. --}}
<form id="cardlink-form" method="POST" action="{{ $endpoint }}" accept-charset="UTF-8" class="mt-2" x-data x-init="$el.submit()">
    @foreach ($fields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <p class="text-sm text-base/70 mb-4">{{ __('invoices.cardlink_redirect') }}</p>
    <button type="submit"
        class="bg-secondary-500 text-white hover:bg-secondary py-2 px-4 rounded-md w-full duration-300">
        {{ __('invoices.cardlink_continue') }}
    </button>
</form>
