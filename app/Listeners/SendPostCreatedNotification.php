<?php

namespace App\Listeners;

use App\Events\SharedMomentCreated;
use App\Notifications\PostCreatedNotification;

class SendPostCreatedNotification
{
    public function handle(SharedMomentCreated $event): void
    {
        $moment = $event->moment->loadMissing(['author', 'relationship.members']);
        $relationship = $moment->relationship ?? $moment->author->relationships()->with('members')->first();

        if (! $relationship) {
            return;
        }

        foreach ($relationship->members as $member) {
            if (! $member->is($moment->author)) {
                $member->notify(new PostCreatedNotification($moment->author->firstName(), $moment->id));
            }
        }
    }
}
