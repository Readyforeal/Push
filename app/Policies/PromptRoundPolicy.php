<?php

namespace App\Policies;

use App\Models\PromptRound;
use App\Models\User;

class PromptRoundPolicy
{
    public function view(User $user, PromptRound $round): bool
    {
        return $round->relationship->hasMember($user);
    }

    public function viewResults(User $user, PromptRound $round): bool
    {
        return $round->isRevealed() && $this->view($user, $round);
    }
}
