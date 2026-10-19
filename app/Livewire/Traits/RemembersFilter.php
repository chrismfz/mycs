<?php

namespace App\Livewire\Traits;

/**
 * A list's filter kept for the session (P4b): back on the list, it shows what was chosen last, unless its link says
 * otherwise (`?status=…`, e.g. «Pay» on the home page).
 */
trait RemembersFilter
{
    protected function recallFilter(string $list, string $property = 'status'): void
    {
        if (!request()->query->has($property)) {
            $this->{$property} = (string) session("portal.filters.{$list}", '');
        }
    }

    protected function rememberFilter(string $list, string $property = 'status'): void
    {
        session(["portal.filters.{$list}" => (string) $this->{$property}]);
    }
}
