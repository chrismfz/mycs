{{-- The customer's mail service: the DNS its domain needs, checked, and its mailboxes (owner 2026-10-28). --}}
<div>
@if ($domain)
<div class="bg-background-secondary rounded-lg p-4 mt-2">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h4 class="text-lg font-semibold">{{ __('services.mailcow.dns_title', ['domain' => $domain->domain]) }}</h4>
        <button type="button" wire:click="recheck" wire:loading.attr="disabled" class="text-sm underline text-base/70 hover:text-base">
            {{ __('services.mailcow.recheck') }}
        </button>
    </div>
    <p class="text-sm mt-1 {{ $check['ok'] ? 'text-success' : 'text-warning' }}">
        {{ $check['ok'] ? __('services.mailcow.dns_ok') : __('services.mailcow.dns_not_ok') }}
    </p>
    <div class="overflow-x-auto mt-3">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-base/60">
                    <th class="py-1 pe-3 font-medium"></th>
                    <th class="py-1 pe-3 font-medium">{{ __('services.mailcow.record') }}</th>
                    <th class="py-1 pe-3 font-medium">{{ __('services.mailcow.name') }}</th>
                    <th class="py-1 font-medium">{{ __('services.mailcow.value') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($check['rows'] as $row)
                <tr class="border-t border-neutral align-top">
                    <td class="py-2 pe-3 whitespace-nowrap" title="{{ __('services.mailcow.status.' . $row['status']) }}">
                        {{ $row['status'] === 'ok' ? '✓' : ($row['status'] === 'fail' ? '✗' : '!') }}
                    </td>
                    <td class="py-2 pe-3 whitespace-nowrap">
                        {{ __('services.mailcow.records.' . $row['key']) }} <span class="text-xs text-base/50">{{ $row['type'] }}</span>
                        @unless ($row['required'])<div class="text-xs text-base/50">{{ __('services.mailcow.advised') }}</div>@endunless
                    </td>
                    <td class="py-2 pe-3 font-mono text-xs break-all">{{ $row['name'] }}</td>
                    <td class="py-2 font-mono text-xs break-all">
                        @if ($row['status'] === 'ok')
                            {{ implode(' · ', $row['found']) }}
                        @else
                            @if ($row['expected'] !== '')<div>{{ __('services.mailcow.set') }} <span class="select-all">{{ $row['expected'] }}</span></div>@endif
                            @if ($row['found'])<div class="text-base/50 mt-1">{{ __('services.mailcow.now') }} {{ implode(' · ', $row['found']) }}</div>@endif
                            @if ($row['note'])<div class="text-base/50 mt-1 font-sans">{{ __('services.mailcow.notes.' . $row['note']) }}</div>@endif
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="text-xs text-base/50 mt-2">{{ __('services.mailcow.checked_at', ['when' => \Illuminate\Support\Carbon::createFromTimestamp($check['checked_at'])->diffForHumans()]) }}</p>
</div>

<div class="bg-background-secondary rounded-lg p-4 mt-2">
    <h4 class="text-lg font-semibold">{{ __('services.mailcow.mailboxes_title') }}</h4>
    <p class="text-sm text-base/70 mt-1">
        {{ __('services.mailcow.totals', ['mailboxes' => $domain->mailboxes, 'used' => \Illuminate\Support\Number::fileSize($domain->used_bytes, 1),
            'quota' => $domain->quota_bytes ? \Illuminate\Support\Number::fileSize($domain->quota_bytes) : '∞']) }}
    </p>
    @if ($mailboxes->isEmpty())
        <p class="text-sm text-base/50 mt-2">{{ __('services.mailcow.no_mailboxes') }}</p>
    @else
    <div class="overflow-x-auto mt-2">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-base/60">
                    <th class="py-1 pe-3 font-medium">{{ __('services.mailcow.mailbox') }}</th>
                    <th class="py-1 pe-3 font-medium">{{ __('services.mailcow.used') }}</th>
                    <th class="py-1 font-medium">{{ __('services.mailcow.last_login') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($mailboxes as $mailbox)
                @php($percent = $mailbox->quota_bytes > 0 ? min(100, (int) round(100 * $mailbox->used_bytes / $mailbox->quota_bytes)) : null)
                <tr class="border-t border-neutral">
                    <td class="py-2 pe-3">
                        <span @class(['line-through text-base/50' => !$mailbox->active])>{{ $mailbox->address }}</span>
                        @if ($mailbox->name)<div class="text-xs text-base/50">{{ $mailbox->name }}</div>@endif
                    </td>
                    <td class="py-2 pe-3 whitespace-nowrap">
                        {{ \Illuminate\Support\Number::fileSize($mailbox->used_bytes, 1) }} / {{ $mailbox->quota_bytes ? \Illuminate\Support\Number::fileSize($mailbox->quota_bytes) : '∞' }}
                        @if ($percent !== null)
                        <div class="mt-1 h-1.5 w-28 rounded bg-neutral">
                            <div @class(['h-1.5 rounded', 'bg-error' => $percent >= 90, 'bg-warning' => $percent >= 75 && $percent < 90, 'bg-success' => $percent < 75]) style="width: {{ $percent }}%"></div>
                        </div>
                        @endif
                    </td>
                    <td class="py-2 whitespace-nowrap text-base/60">{{ $mailbox->last_login_at?->diffForHumans() ?? __('services.mailcow.never') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    <p class="text-xs text-base/50 mt-2">{{ __('services.usage.read_at', ['when' => $domain->last_seen_at?->format('d/m/Y H:i')]) }}</p>
</div>
@endif
</div>
