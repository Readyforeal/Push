<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class SecretMissionCompletedNotification extends Notification
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
            ->title("{$this->partnerName} completed a secret mission")
            ->body('Something thoughtful was done just for you.')
            ->icon('/apple-touch-icon.png')
            ->badge('/apple-touch-icon.png')
            ->tag('secret-mission-completed')
            ->data(['url' => route('missions')]);
    }
}
