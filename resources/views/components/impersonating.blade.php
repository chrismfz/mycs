@if(session()->has('impersonating'))
    <div class="fixed bottom-0 right-0 z-50 flex gap-2 justify-center items-center bg-background-secondary shadow-lg p-4 w-full border-t border-neutral">
        <p>
            {{ __('You are currently impersonating') }}: 
            <strong>{{  auth()->user()->name }}</strong>
        </p>
        {{-- A form (CSRF): the admin's pages no longer end it, since it has its own tab (owner 2026-10-26). --}}
        <form method="POST" action="{{ route('impersonation.leave') }}">
            @csrf
            <x-button.primary type="submit">
                {{ __('Leave') }}
            </x-button.primary>
        </form>
    </div>
@endif