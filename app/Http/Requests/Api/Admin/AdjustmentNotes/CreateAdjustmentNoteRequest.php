<?php

namespace App\Http\Requests\Api\Admin\AdjustmentNotes;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class CreateAdjustmentNoteRequest extends AdminApiRequest
{
    protected $permission = 'adjustment_notes.create';

    public function rules(): array
    {
        return [
            /**
             * A decimal string: negative is a credit note (it lowers the total), positive a debit note.
             *
             * @example -3.00
             */
            'amount' => ['required', 'string', self::SIGNED_AMOUNT],
            'description' => ['required', 'string', 'max:65535', self::maxBytes()],
        ];
    }
}
