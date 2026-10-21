<?php

namespace App\Admin\Resources\ClientResource\Concerns;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Pages\ClientSummaryPage;
use App\Models\Client;

/**
 * Every tab of a client says whose it is (owner 2026-10-21: only the Summary did): «#2 Name» as the title, the tab
 * under it, and the way back to the client's Summary in the breadcrumbs.
 */
trait NamesTheClient
{
    public function getTitle(): string
    {
        $client = $this->getRecord();

        return $client instanceof Client ? '#' . $client->id . ' ' . $client->name : static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return $this instanceof ClientSummaryPage ? null : static::getNavigationLabel();
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        $client = $this->getRecord();

        return [
            ClientResource::getUrl('index') => ClientResource::getBreadcrumb(),
            ClientResource::getUrl('view', ['record' => $client]) => $this->getTitle(),
            static::getNavigationLabel(),
        ];
    }
}
