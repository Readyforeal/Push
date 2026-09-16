<?php

namespace App\Policies;

use App\Models\PhotoSelection;
use App\Models\User;

class PhotoSelectionPolicy
{
    public function view(User $user, PhotoSelection $selection): bool
    {
        $round = $selection->task->round;

        return $round->isRevealed() && $round->relationship->hasMember($user);
    }
}
