<?php

namespace App\Listeners;

use App\Enums\PromptRoundKind;
use App\Enums\PromptTaskKind;
use App\Events\PromptRoundTaskActivated;
use App\Notifications\PromptReadyNotification;
use Illuminate\Support\Str;

class SendPromptReadyNotification
{
    public function handle(PromptRoundTaskActivated $event): void
    {
        $task = $event->task->loadMissing([
            'round',
            'dependency.assignee',
            'dependency.questionResponse',
        ]);

        if ($task->round->kind === PromptRoundKind::PhotoRequest
            && $task->kind === PromptTaskKind::PhotoUpload) {
            $request = $task->dependency->questionResponse->answer;
            $requester = $task->dependency->assignee->name;
            $notification = new PromptReadyNotification(
                title: "{$requester} sent a photo request",
                body: Str::limit($request, 120),
                tag: 'photo-request-ready',
            );
        } elseif ($task->kind === PromptTaskKind::PhotoPick) {
            $notification = new PromptReadyNotification(
                title: 'Photos are ready to choose',
                body: 'Take a look and pick the one you love most.',
                tag: 'photo-pick-ready',
            );
        } else {
            $notification = new PromptReadyNotification;
        }

        $task->assignee->notify($notification);
    }
}
