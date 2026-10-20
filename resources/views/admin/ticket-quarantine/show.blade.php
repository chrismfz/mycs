{{-- A quarantined mail, for staff: every value is the sender's, shown escaped as text (never its HTML). --}}
<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-3 gap-x-4 gap-y-1">
        <dt class="text-gray-500">{{ __('Reason') }}</dt><dd class="col-span-2">{{ $record->reasonLabel() }}@if($record->auth_detail) ({{ $record->auth_detail }})@endif</dd>
        <dt class="text-gray-500">{{ __('From') }}</dt><dd class="col-span-2">{{ $record->from_name }} &lt;{{ $record->from_email }}&gt;</dd>
        <dt class="text-gray-500">{{ __('Vouched for by our server') }}</dt><dd class="col-span-2">{{ $record->authenticated ? __('Yes') : __('No') }}</dd>
        <dt class="text-gray-500">{{ __('Subject') }}</dt><dd class="col-span-2">{{ $record->subject }}</dd>
        <dt class="text-gray-500">{{ __('Sent') }}</dt><dd class="col-span-2">{{ $record->sent_at?->toDayDateTimeString() ?? '—' }}</dd>
        @if($record->suggestedTicket)
            <dt class="text-gray-500">{{ __('Suggested ticket') }}</dt><dd class="col-span-2">#{{ $record->suggestedTicket->reference }} · {{ $record->suggestedTicket->subject }}</dd>
        @endif
        @if($record->refused_files)
            <dt class="text-gray-500">{{ __('Files not kept') }}</dt>
            <dd class="col-span-2">{{ collect($record->refused_files)->map(fn ($file) => $file['name'] . ' (' . $file['reason'] . ')')->implode(', ') }}</dd>
        @endif
    </dl>
    <pre class="whitespace-pre-wrap rounded-lg bg-gray-50 p-3 dark:bg-white/5">{{ $record->preview }}</pre>
</div>
