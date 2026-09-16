<?php

namespace App\Listeners;

use App\Enums\PromptRoundKind;
use App\Events\PromptRoundRevealed;
use App\Notifications\RoundResultReadyNotification;

class SendRoundResultReadyNotification
{
    public function handle(PromptRoundRevealed $event): void
    {
        if (in_array($event->round->kind, [PromptRoundKind::PhotoPicker, PromptRoundKind::PhotoRequest], true)) {
            return;
        }

        foreach ($event->round->relationship->members as $member) {
            if ($member->is($event->completedBy)) {
                continue;
            }

            $member->notify(new RoundResultReadyNotification($event->completedBy->name));
        }
    }
}
