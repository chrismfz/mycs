@php
    use App\Models\EmailLog;
    use App\Models\Invoice;
    use App\Models\Service;
    $d = $summary['details'];
    $inv = $summary['invoices'];
    $svc = $summary['services'];
    $other = $summary['other'];
    $row = 'flex justify-between gap-4 py-1 border-b border-gray-100 dark:border-white/5 text-sm';
    $label = 'text-gray-500 dark:text-gray-400';
@endphp

<div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
    <x-filament::section :heading="__('Client Information')">
        @foreach ([
            __('Name') => $d['name'], __('Company') => $d['company'], __('Email') => $d['email'],
            __('Address') => $d['address'], __('Address 2') => $d['address2'], __('City') => $d['city'], __('State') => $d['state'],
            __('Postcode') => $d['postcode'], __('Country') => $d['country'], __('Phone') => $d['phone'],
            __('Tax ID') => $d['tax_id'], __('EU VAT number') => $d['vat_number'],
        ] as $name => $value)
            <div class="{{ $row }}"><span class="{{ $label }}">{{ $name }}</span><span class="text-right break-all">{{ $value ?: '—' }}</span></div>
        @endforeach
    </x-filament::section>

    @if ($inv !== null || $summary['credit'] !== null)
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
            <div class="flex flex-wrap gap-3 pt-3 text-sm">
                @if ($links['invoices'])<x-filament::link :href="$links['invoices']">{{ __('Invoices') }}</x-filament::link>@endif
                @if ($links['credits'])<x-filament::link :href="$links['credits']">{{ __('Credits') }}</x-filament::link>@endif
            </div>
        </x-filament::section>
    @endif

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
        @endif

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

@if ($svc !== null)
    <x-filament::section :heading="__('Products/Services')" class="mt-6">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left {{ $label }}">
                        <th class="py-2 pr-3">ID</th><th class="py-2 pr-3">{{ __('Product/Service') }}</th><th class="py-2 pr-3">{{ __('Amount') }}</th>
                        <th class="py-2 pr-3">{{ __('Billing Cycle') }}</th><th class="py-2 pr-3">{{ __('Signup Date') }}</th><th class="py-2 pr-3">{{ __('Next Due Date') }}</th><th class="py-2">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($svc['rows'] as $s)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3">@if ($links['service'])<x-filament::link :href="($links['service'])($s['id'])">{{ $s['id'] }}</x-filament::link>@else{{ $s['id'] }}@endif</td>
                            <td class="py-2 pr-3">{{ $s['product'] }}@if ($s['domain']) <span class="{{ $label }}">- {{ $s['domain'] }}</span>@endif</td>
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $s['amount'] }}</td>
                            <td class="py-2 pr-3">{{ $s['cycle'] }}</td>
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $s['created']?->format('d/m/Y') }}</td>
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $s['expires']?->format('d/m/Y') ?? '—' }}</td>
                            <td class="py-2"><x-filament::badge :color="Service::statusColor($s['status'])">{{ Service::statusLabel($s['status']) }}</x-filament::badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-3 {{ $label }}">{{ __('No services') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
@endif
