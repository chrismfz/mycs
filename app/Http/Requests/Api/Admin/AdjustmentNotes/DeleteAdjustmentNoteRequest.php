<?php

namespace App\Http\Requests\Api\Admin\AdjustmentNotes;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class DeleteAdjustmentNoteRequest extends AdminApiRequest
{
    protected $permission = 'adjustment_notes.delete';
}
