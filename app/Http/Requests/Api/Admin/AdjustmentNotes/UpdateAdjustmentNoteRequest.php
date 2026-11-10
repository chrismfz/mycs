<?php

namespace App\Http\Requests\Api\Admin\AdjustmentNotes;

use App\Http\Requests\Api\Admin\AdminApiRequest;

/** A note staff wrote: its amount and description (the type follows the sign). Void and unvoid take no fields. */
class UpdateAdjustmentNoteRequest extends AdminApiRequest
{
    protected $permission = 'adjustment_notes.update';

    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'required', 'string', self::SIGNED_AMOUNT],
            'description' => ['sometimes', 'required', 'string', 'max:65535', self::maxBytes()],
        ];
    }
}
