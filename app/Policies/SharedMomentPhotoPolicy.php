<?php

namespace App\Policies;

use App\Models\SharedMomentPhoto;
use App\Models\User;

class SharedMomentPhotoPolicy
{
    public function view(User $user, SharedMomentPhoto $photo): bool
    {
        $moment = $photo->moment;

        if ($moment->relationship_id === null) {
            if ($moment->user_id === $user->id) {
                return true;
            }

            return $user->relationships()
                ->whereHas('members', fn ($members) => $members->whereKey($moment->user_id))
                ->exists();
        }

        return $moment->relationship?->hasMember($user) ?? false;
    }
}
