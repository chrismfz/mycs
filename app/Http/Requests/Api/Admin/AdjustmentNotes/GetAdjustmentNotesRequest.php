<?php

namespace App\Http\Requests\Api\Admin\AdjustmentNotes;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class GetAdjustmentNotesRequest extends AdminApiRequest
{
    protected $permission = 'adjustment_notes.view';
}
