{{-- A mail domain's mailboxes as mailcow:sync last read them (Utilities → Mail Domains). --}}
@if ($mailboxes->isEmpty())
    <p class="text-sm text-gray-500">No mailbox.</p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1 pe-3 font-medium">Mailbox</th>
                    <th class="py-1 pe-3 font-medium">Used / quota</th>
                    <th class="py-1 pe-3 font-medium text-end">Messages</th>
                    <th class="py-1 font-medium">Last login</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($mailboxes as $mailbox)
                    @php($percent = $mailbox->quota_bytes > 0 ? min(100, (int) round(100 * $mailbox->used_bytes / $mailbox->quota_bytes)) : null)
                    <tr class="border-t border-gray-100 dark:border-white/10">
                        <td class="py-1.5 pe-3">
                            <span @class(['line-through text-gray-400' => !$mailbox->active])>{{ $mailbox->address }}</span>
                            @if ($mailbox->name)<div class="text-xs text-gray-500">{{ $mailbox->name }}</div>@endif
                        </td>
                        <td class="py-1.5 pe-3 whitespace-nowrap">
                            {{ \Illuminate\Support\Number::fileSize($mailbox->used_bytes, 1) }} / {{ $mailbox->quota_bytes ? \Illuminate\Support\Number::fileSize($mailbox->quota_bytes) : '∞' }}
                            @if ($percent !== null)
                                <div class="mt-0.5 h-1 w-24 rounded bg-gray-200 dark:bg-white/10">
                                    <div @class(['h-1 rounded', 'bg-danger-500' => $percent >= 90, 'bg-warning-500' => $percent >= 75 && $percent < 90, 'bg-success-500' => $percent < 75]) style="width: {{ $percent }}%"></div>
                                </div>
                            @endif
                        </td>
                        <td class="py-1.5 pe-3 text-end">{{ number_format($mailbox->messages, 0, ',', '.') }}</td>
                        <td class="py-1.5 whitespace-nowrap text-gray-500">{{ $mailbox->last_login_at?->diffForHumans() ?? 'never' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
