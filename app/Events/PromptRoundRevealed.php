<?php

namespace App\Events;

use App\Models\PromptRound;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PromptRoundRevealed implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PromptRound $round,
        public User $completedBy,
    ) {}
}
