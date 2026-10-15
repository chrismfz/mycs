<!DOCTYPE html>
{{-- Where the vPOS sends the customer back (PR H2a): on to the invoice. The vPOS's own leg gets the same page. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta http-equiv="refresh" content="{{ $paid ? 0 : 3 }};url={{ $url }}">
    <title>{{ config('app.name') }}</title>
</head>
<body style="font-family: sans-serif; text-align: center; padding: 3rem 1rem;">
    <p>{{ $paid ? __('invoices.cardlink_paid') : ($known ? __('invoices.cardlink_not_paid') : __('invoices.cardlink_unknown')) }}</p>
    <p><a href="{{ $url }}">{{ __('invoices.cardlink_back') }}</a></p>
</body>
</html>
