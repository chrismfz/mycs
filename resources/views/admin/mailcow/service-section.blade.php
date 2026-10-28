{{-- The «Mail» section of a service's admin page: its mail setup (MailSetup, staff's view). --}}
@livewire(\App\Livewire\Services\MailSetup::class, ['service' => $getRecord(), 'admin' => true], key('mail-setup-' . $getRecord()->getKey()))
