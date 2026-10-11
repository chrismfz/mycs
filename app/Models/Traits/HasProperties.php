<?php

namespace App\Models\Traits;

use App\Models\Property;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasProperties
{
    /**
     * Get the properties of the model which has this trait
     *
     * @return MorphMany<Property, $this>
     */
    public function properties(): MorphMany
    {
        return $this->morphMany(Property::class, 'model');
    }
}
