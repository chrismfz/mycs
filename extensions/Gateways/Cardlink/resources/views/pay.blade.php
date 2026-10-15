{{-- The signed form to the Cardlink vPOS (PR H2a): sent at once; the button is there if the script is not run. --}}
<form id="cardlink-form" method="POST" action="{{ $endpoint }}" accept-charset="UTF-8" class="mt-2">
    @foreach ($fields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <p class="text-sm text-base/70 mb-4">{{ __('invoices.cardlink_redirect') }}</p>
    <button type="submit"
        class="bg-secondary-500 text-white hover:bg-secondary py-2 px-4 rounded-md w-full duration-300">
        {{ __('invoices.cardlink_continue') }}
    </button>
</form>
@script
<script>
    document.getElementById('cardlink-form').submit();
</script>
@endscript
