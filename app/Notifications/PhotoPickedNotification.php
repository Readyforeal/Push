<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class PhotoPickedNotification extends Notification
{
    public function __construct(public string $partnerName) {}

    /** @return array<int, class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("{$this->partnerName} picked a favorite")
            ->body('See which photo stood out to them.')
            ->icon('/apple-touch-icon.png')
            ->badge('/apple-touch-icon.png')
            ->tag('photo-picker-result')
            ->data(['url' => route('library')]);
    }
}
