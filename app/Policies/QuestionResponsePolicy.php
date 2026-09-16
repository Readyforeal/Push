<?php

namespace App\Policies;

use App\Models\QuestionResponse;
use App\Models\User;

class QuestionResponsePolicy
{
    public function view(User $user, QuestionResponse $response): bool
    {
        $task = $response->task;
        $round = $task->round;

        if (! $round->relationship->hasMember($user)) {
            return false;
        }

        return $task->user_id === $user->id || $round->isRevealed();
    }
}
