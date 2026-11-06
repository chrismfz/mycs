{{-- The daily summary email (App\Services\Ops\CronSummary): a fixed view of the code, never a staff-edited template. --}}
@php($danger = 'color:#b91c1c')
@if ($failed)
<p style="{{ $danger }}"><strong>{{ __('Something to look at: a day the cron did not run, a card that was not charged or a module action left open.') }}</strong></p>
@endif

@foreach ($days as $day)
<p style="margin:16px 0 4px"><strong><a href="{{ $day['url'] }}">{{ $day['date']->translatedFormat('l j/n/Y') }}</a></strong></p>
@if (!$day['ran'])
<p style="{{ $danger }}">{{ __('the cron did not run') }}</p>
@else
<p style="margin:0">
    {{ trans_choice(':count invoice|:count invoices', $day['cell']['invoices']) }}
    · {{ __(':ok/:declined cards', ['ok' => $day['cell']['charged'], 'declined' => $day['cell']['declined']]) }}
    · {{ __(':s susp. · :t term.', ['s' => $day['cell']['suspended'], 't' => $day['cell']['terminated']]) }}
</p>
@endif
@if ($day['charges']->isNotEmpty())
<p style="margin:8px 0 2px">{{ __('Cards not charged') }} ({{ $day['charges']->count() }})</p>
<table cellpadding="4" cellspacing="0" border="1" style="border-collapse:collapse">
    @foreach ($day['charges'] as $charge)
    <tr>
        <td>{{ __('Invoice') }} #{{ $charge->invoice?->number ?? $charge->invoice_id }}</td>
        <td>{{ $charge->invoice?->client?->name }}</td>
        <td>{{ $charge->amount }} {{ $charge->currency_code }}</td>
        <td>{{ $charge->status }}@if ($charge->error): {{ \Illuminate\Support\Str::limit($charge->error, 120) }}@endif</td>
    </tr>
    @endforeach
</table>
@endif
@if ($day['actions']->isNotEmpty())
<p style="margin:8px 0 2px">{{ __('Module actions left open') }} ({{ $day['actions']->count() }})</p>
<table cellpadding="4" cellspacing="0" border="1" style="border-collapse:collapse">
    @foreach ($day['actions'] as $action)
    <tr>
        <td>{{ $action->action }}</td>
        <td>
            @if ($action->service)<a href="{{ $serviceUrl($action->service) }}">#{{ $action->service->id }}</a> {{ $action->service->product?->name }} · {{ $action->service->client?->name }}
            @elseif ($action->domain){{ $action->domain->fqdn }}
            @endif
        </td>
        <td>{{ \Illuminate\Support\Str::limit((string) $action->last_error, 120) }}</td>
    </tr>
    @endforeach
</table>
@endif
@endforeach

<p style="margin:20px 0 4px"><strong>{{ __('What the cron suspends and terminates if nothing is paid') }}</strong></p>
@if ($netOn)
<p style="color:#b45309">{{ __('The safety net is on (Settings → Cron): what is due waits until «Enable Suspension» is on again.') }}</p>
@endif
@if ($forecastError !== null)
<p style="{{ $danger }}">{{ __('Could not be worked out: :error', ['error' => \Illuminate\Support\Str::limit($forecastError, 200)]) }}</p>
@endif
@foreach ($due as $row)
<p style="margin:10px 0 2px"><a href="{{ $row['url'] }}">{{ $row['label'] }}</a>: {{ __(':s to suspend · :t to terminate', ['s' => $row['suspend']['count'], 't' => $row['terminate']['count']]) }}</p>
@foreach (['suspend' => __('Suspend'), 'terminate' => __('Terminate')] as $what => $title)
@if ($row[$what]['count'] > 0)
<table cellpadding="4" cellspacing="0" border="1" style="border-collapse:collapse;margin-bottom:4px">
    @foreach ($row[$what]['rows'] as $service)
    <tr>
        <td>{{ $title }}</td>
        <td><a href="{{ $serviceUrl($service) }}">#{{ $service->id }}</a></td>
        <td>{{ $service->product?->name }} · {{ $service->label }}</td>
        <td>{{ $service->client?->name }}</td>
        <td>{{ __('due :date', ['date' => $service->expires_at?->toDateString()]) }}</td>
    </tr>
    @endforeach
</table>
@if ($row[$what]['count'] > $row[$what]['rows']->count())
<p style="margin:0">{{ __('and :count more', ['count' => $row[$what]['count'] - $row[$what]['rows']->count()]) }}</p>
@endif
@endif
@endforeach
@endforeach

<p style="margin-top:20px"><a href="{{ $calendarUrl }}">{{ __('Cron calendar') }}</a> · <a href="{{ $settingsUrl }}">{{ __('Change who gets this email, or when') }}</a></p>
