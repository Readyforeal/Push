<?php

namespace App\Policies;

use App\Enums\PromptTaskKind;
use App\Enums\PromptTaskStatus;
use App\Models\RoundPhoto;
use App\Models\User;

class RoundPhotoPolicy
{
    public function view(User $user, RoundPhoto $photo): bool
    {
        $uploadTask = $photo->task;
        $round = $uploadTask->round;

        if (! $round->relationship->hasMember($user)) {
            return false;
        }

        if ($uploadTask->user_id === $user->id || $round->isRevealed()) {
            return true;
        }

        return $round->tasks()
            ->where('user_id', $user->id)
            ->where('kind', PromptTaskKind::PhotoPick)
            ->where('status', '!=', PromptTaskStatus::Locked)
            ->exists();
    }
}
