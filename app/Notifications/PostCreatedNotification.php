<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class PostCreatedNotification extends WebPushNotification
{
    public function __construct(
        public string $partnerName,
        public int $momentId,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("{$this->partnerName} shared a new post")
            ->body('Take a look at what they shared with you.')
            ->icon('/apple-touch-icon.png')
            ->badge('/apple-touch-icon.png')
            ->tag("post-created-{$this->momentId}")
            ->data(['url' => route('moments.show', $this->momentId)]);
    }
}
