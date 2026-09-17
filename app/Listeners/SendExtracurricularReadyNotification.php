<?php

namespace App\Listeners;

use App\Enums\PromptTaskStatus;
use App\Events\ExtracurricularTaskCompleted;
use App\Notifications\ExtracurricularReadyNotification;

class SendExtracurricularReadyNotification
{
    public function handle(ExtracurricularTaskCompleted $event): void
    {
        $recipientIds = $event->round->tasks()
            ->where('status', PromptTaskStatus::Active)
            ->where('user_id', '!=', $event->completedBy->id)
            ->pluck('user_id')
            ->unique();

        foreach ($event->round->relationship->members()->whereKey($recipientIds)->get() as $member) {
            $member->notify(new ExtracurricularReadyNotification(
                $event->completedBy->firstName(),
                $event->round->id,
            ));
        }
    }
}
