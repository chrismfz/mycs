<?php

namespace App\Models;

class Server extends Extension
{
    protected $table = 'extensions';

    public function newQuery()
    {
        return parent::newQuery()->where('type', 'server');
    }
}
