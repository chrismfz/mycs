<?php

namespace App\Admin\Resources\Audits\Pages;

use App\Admin\Concerns\ShowsLogs;
use App\Admin\Resources\Audits\AuditResource;
use App\Support\AuditSummary;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;

class ListAudits extends ListRecords
{
    use ShowsLogs;

    protected static string $resource = AuditResource::class;

    /** The page's subjects in one query per type, never failing on a class no longer there (AuditSummary). */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();
        AuditSummary::loadSubjects($records instanceof AbstractPaginator || $records instanceof AbstractCursorPaginator ? $records->getCollection() : $records);

        return $records;
    }
}
