<?php

namespace App\Listeners;

use App\Events\PromptPhotoSelected;
use App\Notifications\PhotoPickedNotification;

class SendPhotoSelectedNotification
{
    public function handle(PromptPhotoSelected $event): void
    {
        $uploader = $event->selection->photo->task->assignee;

        if ($uploader->is($event->selectedBy)) {
            return;
        }

        $uploader->notify(new PhotoPickedNotification($event->selectedBy->name));
    }
}
