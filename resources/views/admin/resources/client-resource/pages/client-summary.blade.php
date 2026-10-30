@php
    use App\Models\EmailLog;
    use App\Models\Invoice;
    use App\Models\Contact;
    use App\Models\Service;
    use App\Services\Admin\ClientSummary;
    $d = $summary['details'];
    $set = $summary['settings'];
    $inv = $summary['invoices'];
    $svc = $summary['services'];
    $other = $summary['other'];
    $row = 'flex justify-between gap-4 py-1 border-b border-gray-100 dark:border-white/5 text-sm';
    $label = 'text-gray-500 dark:text-gray-400';
    $group = 'flex items-baseline justify-between pt-4 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $onOff = fn (bool $on) => $on ? __('Yes') : __('No');
    // «With selected» (the owner, 2026-10-24, as WHMCS's): a tick box on each row it may invoice.
    $tick = 'fi-checkbox-input';
    $selectAll = fn (string $property, array $ids) => '$wire.' . $property . ' = $event.target.checked ? ' . \Illuminate\Support\Js::from(array_map('strval', $ids)) . ' : []';
@endphp

{{-- The account's sticky notes, as WHMCS shows them above a client (staff only) --}}
@foreach ($stickyNotes as $note)
<div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 px-4 py-3 text-sm dark:border-warning-500/40 dark:bg-warning-500/10" wire:key="sticky-note-{{ $note->id }}">
    <div class="flex items-start gap-2">
        <x-filament::icon icon="heroicon-s-bookmark" class="mt-0.5 h-4 w-4 shrink-0 text-warning-600 dark:text-warning-400" />
        <div class="min-w-0">
            <div class="whitespace-pre-line break-words text-gray-950 dark:text-white">{{ $note->body }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $note->staff?->name ?? '—' }} · {{ $note->created_at?->format('d/m/Y H:i') }}@if ($note->updated_at?->gt($note->created_at)) · {{ __('edited :date', ['date' => $note->updated_at->format('d/m/Y H:i')]) }}@endif · <a href="{{ \App\Admin\Resources\ClientResource::getUrl('notes', ['record' => $client]) }}" class="underline">{{ __('All notes') }}</a></div>
        </div>
    </div>
</div>
@endforeach

<div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
    {{-- A value at its usual setting is plain grey; one changed from it is a badge (the owner, 2026-10-24). --}}
    <x-filament::section :heading="__('Client Information')">
        <div class="{{ $row }}"><span class="{{ $label }}">{{ __('Name') }}</span><span class="text-right break-all">{{ $d['name'] ?: '—' }}</span></div>
        <div class="{{ $row }} items-center"><span class="{{ $label }}">{{ __('Type') }}</span><span class="text-right">
            @if ($d['type'] === 'business')<x-filament::badge color="info">{{ __('account.contact_type.business') }}</x-filament::badge>
            @elseif ($d['type'])<span class="{{ $label }}">{{ __('account.contact_type.' . $d['type']) }}</span>@else—@endif
        </span></div>
        @foreach ([
            __('Company') => $d['company'], __('Email') => $d['email'],
            __('Address') => $d['address'], __('Address 2') => $d['address2'], __('City') => $d['city'], __('State') => $d['state'],
            __('Postcode') => $d['postcode'], __('Country') => $d['country'], __('Phone') => $d['phone'],
            __('Tax ID') => $d['tax_id'], __('Tax office') => $d['tax_office'], __('Activity') => $d['activity'],
        ] as $name => $value)
            <div class="{{ $row }}"><span class="{{ $label }}">{{ $name }}</span><span class="text-right break-all">{{ $value ?: '—' }}</span></div>
        @endforeach
        <div class="{{ $row }} items-center"><span class="{{ $label }}">{{ __('EU VAT number') }}</span><span class="flex flex-wrap items-center justify-end gap-1 text-right break-all">
            {{ $d['vat_number'] ?: '—' }}
            @if ($d['vat_status'])<x-filament::badge :color="match ($d['vat_status']) { 'valid' => 'success', 'invalid' => 'danger', default => 'gray' }">{{ __('vat.status.' . $d['vat_status']) }}</x-filament::badge>@endif
        </span></div>
        <div class="{{ $row }} items-center"><span class="{{ $label }}">{{ __('Document') }}</span><span class="text-right">
            @if ($d['document_type'] === 'invoice')<x-filament::badge color="info">{{ __('account.document_type.invoice') }}</x-filament::badge>
            @elseif ($d['document_type'])<span class="{{ $label }}">{{ __('account.document_type.' . $d['document_type']) }}</span>@else—@endif
        </span></div>
        @php
            $emails = $d['email_preferences'];
            $missing = $emails === null ? [] : array_values(array_diff(Contact::EMAIL_PREFERENCES, $emails));
            $kind = fn (string $kind) => __('account.email_preference.' . $kind);
        @endphp
        <div class="{{ $row }} items-center" data-setting="email_preferences"><span class="{{ $label }}">{{ __('Receives emails') }}</span><span class="flex flex-wrap items-center justify-end gap-1 text-right">
            @if ($missing === [])<span class="{{ $label }}">{{ __('All') }}</span>
            @elseif ($emails === [])<x-filament::badge color="warning">{{ __('account.email_preferences_none') }}</x-filament::badge>
            @else{{ implode(' · ', array_map($kind, $emails)) }} <x-filament::badge color="warning">{{ __('Not: :list', ['list' => implode(', ', array_map($kind, $missing))]) }}</x-filament::badge>@endif
        </span></div>
    </x-filament::section>

    {{-- Always: its settings are the account's, as its details (only the money needs the invoices' or credits' permission). --}}
        <x-filament::section :heading="__('Invoices/Billing')">
            @if ($inv !== null)
                @foreach ($inv['statuses'] as $status => $line)
                    <div class="{{ $row }}">
                        <span class="{{ $label }}">{{ Invoice::statusLabel($status) }}</span>
                        <span class="text-right">{{ $line['count'] }}@if ($line['total']) ({{ implode(', ', $line['total']) }})@endif</span>
                    </div>
                @endforeach
                <div class="{{ $row }} font-medium"><span>{{ __('Due') }}</span><span class="text-right">{{ $inv['due'] ? implode(', ', $inv['due']) : '—' }}</span></div>
            @endif
            @if ($summary['credit'] !== null)
                <div class="{{ $row }} font-medium"><span>{{ __('Credit Balance') }}</span><span class="text-right">{{ $summary['credit'] ? implode(', ', $summary['credit']) : '—' }}</span></div>
            @endif

            {{-- The Edit page's «Billing» and «Fiscal invoicing (Ekdosi)» (the owner, 2026-10-24). --}}
            <div class="{{ $group }}"><span>{{ __('Billing settings') }}</span>@if ($links['edit'])<x-filament::link :href="$links['edit']" size="sm" class="normal-case tracking-normal">{{ __('Edit') }}</x-filament::link>@endif</div>
            <div class="{{ $row }} items-center" data-setting="language"><span class="{{ $label }}">{{ __('Language') }}</span><span class="text-right">
                @if ($set['language'])<x-filament::badge color="info">{{ $set['language'] }}</x-filament::badge>@else<span class="{{ $label }}">{{ __('Default') }} ({{ $set['language_default'] }})</span>@endif
            </span></div>
            <div class="{{ $row }} items-center"><span class="{{ $label }}">{{ __('Client group') }}</span><span class="text-right">
                @if ($set['group'])
                    <x-filament::badge color="gray">@if ($set['group']['colour'])<span class="me-1 inline-block h-2 w-2 rounded-full" style="background-color: {{ $set['group']['colour'] }}"></span>@endif{{ $set['group']['name'] . ($set['group']['percent'] ? ' −' . (float) $set['group']['percent'] . '%' : '') }}</x-filament::badge>
                @else—@endif
            </span></div>
            <div class="{{ $row }}"><span class="{{ $label }}">{{ __('Payment method for renewals') }}</span><span class="text-right break-all">{{ $set['method'] ?? '—' }}</span></div>
            @foreach ([
                'auto_charge' => __('Charge renewals automatically'), 'credit_auto_apply' => __('Credit pays open invoices automatically'),
                'separate_invoices' => __('Separate invoices'), 'dunning_exempt' => __('No automatic suspension or termination'),
                'no_overdue_reminders' => __('No overdue reminders'), 'tax_exempt' => __('Tax exempt'),
                '' => __('Fiscal invoicing (Ekdosi)'), // a heading, not a setting
                'ignore_invoicing' => __('Ignore invoicing'), 'immediate_invoicing' => __('Immediate invoicing'),
            ] as $field => $name)
                @if ($field === '')
                    <div class="{{ $group }}"><span>{{ $name }}</span></div>
                @else
                    <div class="{{ $row }} items-center" data-setting="{{ $field }}"><span class="{{ $label }}">{{ $name }}</span><span class="text-right">
                        {{-- On, with no method of its own: the cron charges nothing (review F3); a hint, as most pay by transfer. --}}
                        @if ($field === 'auto_charge' && $set['auto_charge'] && $set['method'] === null)<span class="{{ $label }}">{{ __('Yes, no method saved') }}</span>
                        @elseif ($set[$field] === ClientSummary::SETTING_DEFAULTS[$field])<span class="{{ $label }}">{{ $onOff($set[$field]) }}</span>
                        @else<x-filament::badge color="warning">{{ $onOff($set[$field]) }}@if (in_array($field, $set['from_group'] ?? [], true)) {{ __('(group)') }}@endif</x-filament::badge>@endif
                    </span></div>
                @endif
            @endforeach

            <div class="flex flex-wrap gap-3 pt-3 text-sm">
                @if ($links['invoices'])<x-filament::link :href="$links['invoices']">{{ __('Invoices') }}</x-filament::link>@endif
                @if ($links['credits'])<x-filament::link :href="$links['credits']">{{ __('Credits') }}</x-filament::link>@endif
            </div>
        </x-filament::section>

    @if ($svc !== null || $summary['open_tickets'] !== null)
        <x-filament::section :heading="__('Products/Services')">
            @if ($svc !== null)
                @foreach ($svc['counts'] as $status => $count)
                    <div class="{{ $row }}"><span class="{{ $label }}">{{ Service::statusLabel($status) }}</span><span>{{ $count }}</span></div>
                @endforeach
            @endif
            @if ($summary['open_tickets'] !== null)
                <div class="{{ $row }}"><span class="{{ $label }}">{{ __('Open tickets') }}</span><span>{{ $summary['open_tickets'] }}</span></div>
            @endif
            <div class="flex flex-wrap gap-3 pt-3 text-sm">
                @if ($links['services'])<x-filament::link :href="$links['services']">{{ __('Products/Services') }}</x-filament::link>@endif
                @if ($links['tickets'])<x-filament::link :href="$links['tickets']">{{ __('Tickets') }}</x-filament::link>@endif
            </div>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('Other Information')">
        <div class="{{ $row }}"><span class="{{ $label }}">{{ __('Signup Date') }}</span><span>{{ $other['since']?->format('d/m/Y') ?? '—' }}</span></div>
        @if ($other['since'])
            @php $months = (int) $other['since']->diffInMonths(now()); @endphp
            <div class="{{ $row }}"><span class="{{ $label }}">{{ __('Client For') }}</span><span>{{ trans_choice(':count month|:count months', $months, ['count' => $months]) }}</span></div>
        @endif
        <div class="{{ $row }}"><span class="{{ $label }}">{{ __('Owner Email Verified') }}</span><span>{{ $other['owner_verified'] === null ? '—' : ($other['owner_verified'] ? __('Yes') : __('No')) }}</span></div>
        @if ($other['users'] !== null)
            <div class="{{ $row }}"><span class="{{ $label }}">{{ __('Last Login') }}</span><span class="text-right">{{ $other['last_login']?->format('d/m/Y H:i') ?? '—' }}@if ($other['last_login_ip'])<br><span class="{{ $label }}">{{ $other['last_login_ip'] }}</span>@endif</span></div>
            <div class="{{ $row }}"><span class="{{ $label }}">{{ __('Users') }}</span><span>{{ $other['users'] }}</span></div>
            @if ($other['owner_2fa'] !== null)
                <div class="{{ $row }} items-center"><span class="{{ $label }}">{{ __('Owner 2FA') }}</span><span>
                    @if ($other['owner_2fa'])<x-filament::badge color="success">{{ __('Yes') }}</x-filament::badge>@else<span class="{{ $label }}">{{ __('No') }}</span>@endif
                </span></div>
            @endif
        @endif
        <div class="{{ $row }}"><span class="{{ $label }}">{{ __('Contacts') }}</span><span>{{ $other['contacts'] }}</span></div>

        @if ($summary['emails'] !== null)
            <div class="pt-4 text-sm font-medium">{{ __('Recent Emails') }}</div>
            @forelse ($summary['emails'] as $mail)
                <div class="{{ $row }}">
                    <span class="truncate">
                        @if ($links['email'])<x-filament::link :href="($links['email'])($mail->id)">{{ $mail->subject }}</x-filament::link>@else{{ $mail->subject }}@endif
                    </span>
                    <span class="{{ $label }} whitespace-nowrap">{{ $mail->created_at?->format('d/m/Y') }}@if ($mail->status !== EmailLog::STATUS_SENT) · {{ __(ucfirst($mail->status)) }}@endif</span>
                </div>
            @empty
                <div class="text-sm {{ $label }}">—</div>
            @endforelse
        @endif
    </x-filament::section>
</div>

{{-- All time and yearly, net (the owner, 2026-10-21; ClientTotals: the reports' rules). --}}
@if (!empty($yearlyTotals))
    <x-filament::section :heading="__('Totals by year')" class="mt-6" collapsible>
        @foreach ($yearlyTotals as $currency => $totals)
            @php
                $currencyModel = \App\Models\Currency::query()->find($currency);
                $money = fn ($amount) => (new \App\Classes\Price(['price' => $amount, 'currency' => $currencyModel], tax: 0))->format($amount);
            @endphp
            <div class="overflow-x-auto {{ $loop->first ? '' : 'mt-4' }}">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left border-b border-gray-100 dark:border-white/5 {{ $label }}">
                            <th class="py-2 pr-3">{{ count($yearlyTotals) > 1 ? $currency : __('Year') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('Invoiced') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('Paid') }}</th>
                            <th class="py-2 text-right">{{ __('Outstanding') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($totals['years'] as $year)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-3">{{ $year['year'] ?? __('Paid, no date') }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $money($year['invoiced']) }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $money($year['paid']) }}</td>
                                <td class="py-2 text-right whitespace-nowrap">{{ $money($year['outstanding']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="py-2 pr-3">{{ __('All Time') }}</td>
                            <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $money($totals['total']['invoiced']) }}</td>
                            <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $money($totals['total']['paid']) }}</td>
                            <td class="py-2 text-right whitespace-nowrap">{{ $money($totals['total']['outstanding']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endforeach
        <p class="mt-2 text-xs {{ $label }}">{{ __('Net amounts, without tax.') }}</p>
    </x-filament::section>
@endif

@if ($svc !== null)
    <x-filament::section :heading="__('Products/Services')" class="mt-6">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left {{ $label }}">
                        @if ($selectable['services'] !== null)<th class="w-6 py-2 pr-2">@if ($selectable['services'])<input type="checkbox" class="{{ $tick }}" x-data x-on:change="{{ $selectAll('selectedServices', $selectable['services']) }}" x-bind:checked="($wire.selectedServices?.length ?? 0) === {{ count($selectable['services']) }}" aria-label="{{ __('Select all') }}">@endif</th>@endif
                        <th class="py-2 pr-3">ID</th><th class="py-2 pr-3">{{ __('Product/Service') }}</th><th class="py-2 pr-3">{{ __('Amount') }}</th>
                        <th class="py-2 pr-3">{{ __('Billing Cycle') }}</th><th class="py-2 pr-3">{{ __('Signup Date') }}</th><th class="py-2 pr-3">{{ __('Next Due Date') }}</th><th class="py-2">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($svc['rows'] as $s)
                        <tr class="border-t border-gray-100 dark:border-white/5" wire:key="summary-service-{{ $s['id'] }}">
                            @if ($selectable['services'] !== null)<td class="py-2 pr-2">@if (in_array($s['id'], $selectable['services'], true))<input type="checkbox" class="{{ $tick }}" wire:model="selectedServices" value="{{ $s['id'] }}" aria-label="{{ __('Select') }} #{{ $s['id'] }}">@endif</td>@endif
                            <td class="py-2 pr-3">@if ($links['service'])<x-filament::link :href="($links['service'])($s['id'])">{{ $s['id'] }}</x-filament::link>@else{{ $s['id'] }}@endif</td>
                            <td class="py-2 pr-3">{{ $s['product'] }}@if ($s['domain']) <span class="{{ $label }}">- {{ $s['domain'] }}</span>@endif</td>
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $s['amount'] }}</td>
                            <td class="py-2 pr-3">{{ $s['cycle'] }}</td>
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $s['created']?->format('d/m/Y') }}</td>
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $s['expires']?->format('d/m/Y') ?? '—' }}</td>
                            <td class="py-2"><x-filament::badge :color="Service::statusColor($s['status'])">{{ Service::statusLabel($s['status']) }}</x-filament::badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $selectable['services'] !== null ? 8 : 7 }}" class="py-3 {{ $label }}">{{ __('No services') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
@endif

@if ($summary['domains'] !== null)
    <x-filament::section :heading="__('domains.domains')" class="mt-6">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left {{ $label }}">
                        @if ($selectable['domains'] !== null)<th class="w-6 py-2 pr-2">@if ($selectable['domains'])<input type="checkbox" class="{{ $tick }}" x-data x-on:change="{{ $selectAll('selectedDomains', $selectable['domains']) }}" x-bind:checked="($wire.selectedDomains?.length ?? 0) === {{ count($selectable['domains']) }}" aria-label="{{ __('Select all') }}">@endif</th>@endif
                        <th class="py-2 pr-3">ID</th><th class="py-2 pr-3">{{ __('domains.domain') }}</th><th class="py-2 pr-3">{{ __('Registered') }}</th>
                        <th class="py-2 pr-3">{{ __('Expires') }}</th><th class="py-2 pr-3">{{ __('Auto renew') }}</th><th class="py-2">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($summary['domains']['rows'] as $dom)
                        <tr class="border-t border-gray-100 dark:border-white/5" wire:key="summary-domain-{{ $dom['id'] }}">
                            @if ($selectable['domains'] !== null)<td class="py-2 pr-2">@if (in_array($dom['id'], $selectable['domains'], true))<input type="checkbox" class="{{ $tick }}" wire:model="selectedDomains" value="{{ $dom['id'] }}" aria-label="{{ __('Select') }} {{ $dom['fqdn'] }}">@endif</td>@endif
                            <td class="py-2 pr-3">@if ($links['domain'])<x-filament::link :href="($links['domain'])($dom['id'])">{{ $dom['id'] }}</x-filament::link>@else{{ $dom['id'] }}@endif</td>
                            <td class="py-2 pr-3">{{ $dom['fqdn'] }}</td>
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $dom['registered']?->format('d/m/Y') ?? '—' }}</td>
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $dom['expires']?->format('d/m/Y') ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $dom['auto_renew'] ? __('Yes') : __('No') }}</td>
                            <td class="py-2"><x-filament::badge :color="$dom['status']->getColor()">{{ $dom['status']->getLabel() }}</x-filament::badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $selectable['domains'] !== null ? 7 : 6 }}" class="py-3 {{ $label }}">{{ __('No domains') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
@endif

{{-- Under both tables, as WHMCS's «With selected»: one action for now, more may join the list. --}}
@if (($selectable['services'] !== null && $svc !== null) || ($selectable['domains'] !== null && $summary['domains'] !== null))
    <div class="mt-4 flex flex-wrap items-center gap-3 text-sm" x-data="{ get count() { return ($wire.selectedServices?.length ?? 0) + ($wire.selectedDomains?.length ?? 0) } }">
        <span class="{{ $label }}">{{ __('With selected:') }}</span>
        <x-filament::dropdown placement="bottom-start">
            <x-slot name="trigger">
                <x-filament::button color="gray" size="sm" icon="heroicon-m-chevron-down" icon-position="after">{{ __('Choose an action') }}</x-filament::button>
            </x-slot>
            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item icon="heroicon-o-document-duplicate" wire:click="mountAction('invoiceSelectedRows')">{{ __('invoices.selected.action') }}</x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        </x-filament::dropdown>
        <span class="{{ $label }}" x-show="count > 0" x-cloak x-text="{{ \Illuminate\Support\Js::from(__(':count selected')) }}.replace(':count', count)"></span>
    </div>
@endif
