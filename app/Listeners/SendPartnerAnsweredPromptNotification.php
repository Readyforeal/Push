<?php

namespace App\Listeners;

use App\Enums\PromptRoundOrigin;
use App\Events\PromptQuestionAnswered;
use App\Notifications\PartnerAnsweredPromptNotification;

class SendPartnerAnsweredPromptNotification
{
    public function handle(PromptQuestionAnswered $event): void
    {
        if ($event->round->origin === PromptRoundOrigin::Extracurricular) {
            return;
        }

        foreach ($event->round->relationship->members as $member) {
            if ($member->is($event->answeredBy)) {
                continue;
            }

            $member->notify(new PartnerAnsweredPromptNotification($event->answeredBy->firstName()));
        }
    }
}
