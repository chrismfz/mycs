<?php

namespace App\Models;

class Gateway extends Extension
{
    protected $table = 'extensions';

    public function newQuery()
    {
        return parent::newQuery()->where('type', 'gateway');
    }
}
