<?php

namespace App\Resolvers;

use App\Support\Actor;
use OwenIt\Auditing\Contracts\UserResolver;

/** The audits' causer: the staff member in the admin and while logged in as a client, else the customer. */
class ActorResolver implements UserResolver
{
    public static function resolve()
    {
        return Actor::current();
    }
}
