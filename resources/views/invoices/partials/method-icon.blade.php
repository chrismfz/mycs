{{-- A saved method's brand icon, by its type (the old window's). --}}
@switch(strtolower((string) $type))
@case('visa')
<x-icons.visa class="size-8 shrink-0" aria-hidden="true" /> @break
@case('mastercard')
<x-icons.mastercard class="size-8 shrink-0" aria-hidden="true" /> @break
@case('amex')
<x-icons.amex class="size-8 shrink-0" aria-hidden="true" /> @break
@case('american express')
<x-icons.american-express class="size-8 shrink-0" aria-hidden="true" /> @break
@case('discover')
<x-icons.discover class="size-8 shrink-0" aria-hidden="true" /> @break
@case('paypal')
<x-icons.paypal class="size-8 shrink-0" aria-hidden="true" /> @break
@case('sepa_debit')
<x-icons.sepa class="size-8 shrink-0" aria-hidden="true" /> @break
@case('ideal')
<x-icons.ideal class="size-8 shrink-0" aria-hidden="true" /> @break
@case('bancontact')
<x-icons.bancontact class="size-8 shrink-0" aria-hidden="true" /> @break
@case('sofort')
<x-icons.sofort class="size-8 shrink-0" aria-hidden="true" /> @break
@case('us_bank_account')
@case('bacs_debit')
@case('au_becs_debit')
<x-icons.bank-debit class="size-8 shrink-0" aria-hidden="true" /> @break
@default
<x-ri-bank-card-line class="size-6 text-primary shrink-0" aria-hidden="true" />
@endswitch
