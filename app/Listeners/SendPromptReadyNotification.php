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
                url: route('prompts.show', $task->round),
            );
        } elseif ($task->kind === PromptTaskKind::PhotoPick) {
            $notification = new PromptReadyNotification(
                title: 'Photos are ready to choose',
                body: 'Take a look and pick the one you love most.',
                tag: 'photo-pick-ready',
                url: route('prompts.show', $task->round),
            );
        } else {
            $notification = new PromptReadyNotification(url: route('prompts.show', $task->round));
        }

        $task->assignee->notify($notification);
    }
}
