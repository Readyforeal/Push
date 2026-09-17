<?php

namespace App\Listeners;

use App\Events\SharedMomentCommentCreated;
use App\Notifications\PostCommentedNotification;

class SendPostCommentedNotification
{
    public function handle(SharedMomentCommentCreated $event): void
    {
        $comment = $event->comment->loadMissing(['author', 'moment.author', 'moment.relationship.members']);
        $relationship = $comment->moment->relationship
            ?? $comment->moment->author->relationships()->with('members')->first();

        if (! $relationship) {
            return;
        }

        foreach ($relationship->members as $member) {
            if (! $member->is($comment->author)) {
                $member->notify(new PostCommentedNotification(
                    $comment->author->firstName(),
                    $comment->moment->id,
                ));
            }
        }
    }
}
