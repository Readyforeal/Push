<?php

namespace App\Policies;

use App\Models\SharedMoment;
use App\Models\User;

class SharedMomentPolicy
{
    public function view(User $user, SharedMoment $moment): bool
    {
        return $moment->isVisibleTo($user);
    }
}
