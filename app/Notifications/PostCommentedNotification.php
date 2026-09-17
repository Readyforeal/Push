<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class PostCommentedNotification extends WebPushNotification
{
    public function __construct(
        public string $partnerName,
        public int $momentId,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("{$this->partnerName} commented on a post")
            ->body('There’s something new in the conversation.')
            ->icon('/apple-touch-icon.png')
            ->badge('/apple-touch-icon.png')
            ->tag("post-commented-{$this->momentId}")
            ->data(['url' => route('moments.show', $this->momentId)]);
    }
}
