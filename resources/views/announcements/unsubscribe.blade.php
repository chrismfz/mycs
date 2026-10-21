<!DOCTYPE html>
{{-- A mass mail's «stop these emails» (BulkMailer): confirmed with a button, so a mail scanner opening the link changes nothing. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name') }}</title>
</head>
<body style="font-family: sans-serif; text-align: center; padding: 3rem 1rem; max-width: 36rem; margin: 0 auto;">
    @if ($done)
        <p>{{ __('account.news_stopped') }}</p>
    @else
        <p>{{ __('account.news_stop_question') }}</p>
        <form method="POST" action="{{ $action }}">
            @csrf
            <button type="submit" style="padding: 0.6rem 1.2rem; cursor: pointer;">{{ __('account.news_stop') }}</button>
        </form>
    @endif
    <p style="font-size: 0.9rem; color: #6b7280; margin-top: 2rem;">{{ __('account.news_stop_note') }}</p>
</body>
</html>
