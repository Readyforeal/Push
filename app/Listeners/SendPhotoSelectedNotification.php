<?php

namespace App\Listeners;

use App\Enums\PromptRoundOrigin;
use App\Events\PromptPhotoSelected;
use App\Notifications\PhotoPickedNotification;

class SendPhotoSelectedNotification
{
    public function handle(PromptPhotoSelected $event): void
    {
        if ($event->selection->task->round->origin === PromptRoundOrigin::Extracurricular) {
            return;
        }

        $uploader = $event->selection->photo->task->assignee;

        if ($uploader->is($event->selectedBy)) {
            return;
        }

        $uploader->notify(new PhotoPickedNotification($event->selectedBy->firstName()));
    }
}
