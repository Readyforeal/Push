<?php

namespace App\Events;

use App\Models\PhotoSelection;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PromptPhotoSelected implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PhotoSelection $selection,
        public User $selectedBy,
    ) {}
}
