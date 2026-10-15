{{-- The mail as sent, isolated from the admin page: no scripts, no same-origin access. --}}
<iframe sandbox srcdoc="{{ $getRecord()->body }}" style="width: 100%; height: 700px; border: none;"></iframe>
