<?php

namespace App\Admin\Concerns;

use App\Admin\AdminSidebar;
use Illuminate\Database\Eloquent\Model;

/**
 * A record page (a client, a service, an invoice, a ticket, a domain) remembered for the sidebar's «Recently viewed»
 * (AdminSidebar), as WHMCS's recent clients: by its title, at its view page (else its edit page).
 */
trait RemembersRecent
{
    public function renderingRemembersRecent(): void
    {
        $record = $this->record ?? null;
        if (!$record instanceof Model || !$record->exists) {
            return;
        }
        $resource = static::getResource();
        $page = $resource::hasPage('view') ? 'view' : 'edit';
        try {
            AdminSidebar::remember($resource::getModelLabel(), $record, (string) $resource::getRecordTitle($record), $resource::getUrl($page, ['record' => $record]));
        } catch (\Throwable) {
            // A page whose URL cannot be built is just not remembered.
        }
    }
}
