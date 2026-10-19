@props(['status'])
{{-- A ticket's status as the customer reads it (P4b): who it waits on, not WHMCS's «Open» or «Replied». --}}
@php
    $known = in_array($status, ['open', 'replied', 'closed'], true);
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium whitespace-nowrap ' . match ($status) {
    'replied' => 'text-warning bg-warning/20',
    'closed' => 'text-inactive bg-inactive/20',
    default => 'text-info bg-info/20',
}]) }}>
    @if ($status === 'replied')
        <x-ri-chat-smile-2-fill class="size-3.5" aria-hidden="true" />
    @elseif ($status === 'closed')
        <x-ri-forbid-fill class="size-3.5" aria-hidden="true" />
    @else
        <x-ri-time-line class="size-3.5" aria-hidden="true" />
    @endif
    {{ $known ? __('ticket.statuses.' . $status) : ucfirst((string) $status) }}
</span>
