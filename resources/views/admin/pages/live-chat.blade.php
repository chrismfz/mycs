<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <x-filament::section :heading="__('Team')" class="lg:col-span-1">
            @livewire('admin.staff-team', key('mycs-staff-team'))
        </x-filament::section>
        <x-filament::section :heading="__('Staff room')" class="lg:col-span-3">
            @livewire('admin.staff-room', key('mycs-staff-room'))
        </x-filament::section>
    </div>
</x-filament-panels::page>
