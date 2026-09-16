<?php

namespace App\Policies;

use App\Models\SharedMomentPhoto;
use App\Models\User;

class SharedMomentPhotoPolicy
{
    public function view(User $user, SharedMomentPhoto $photo): bool
    {
        return $photo->moment->isVisibleTo($user);
    }
}
