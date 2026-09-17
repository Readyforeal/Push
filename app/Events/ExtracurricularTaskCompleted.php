<?php

namespace App\Events;

use App\Models\PromptRound;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExtracurricularTaskCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PromptRound $round,
        public User $completedBy,
    ) {}
}
