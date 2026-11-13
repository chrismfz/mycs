{{-- A bulk license's part of the «License» section on its service's admin page (BulkLicenseStaff). --}}
@livewire(\App\Livewire\Admin\BulkLicenseStaff::class, ['service' => $getRecord()], key('bulk-license-' . $getRecord()->getKey()))
