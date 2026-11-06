<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Models\Audit as AuditModel;

class Audit extends AuditModel
{
    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Who did it: the package's own relation, typed so that eager loading it is checked.
     *
     * @return MorphTo<Model, $this>
     */
    public function user(): MorphTo
    {
        $prefix = config('audit.user.morph_prefix', 'user');

        return $this->morphTo(__FUNCTION__, $prefix . '_type', $prefix . '_id');
    }
}
