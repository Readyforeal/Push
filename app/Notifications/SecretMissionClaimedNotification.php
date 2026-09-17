<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class SecretMissionClaimedNotification extends WebPushNotification
{
    public function __construct(public string $partnerName) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("{$this->partnerName} took a secret mission")
            ->body('A little something is in motion for you.')
            ->icon('/apple-touch-icon.png')
            ->badge('/apple-touch-icon.png')
            ->tag('secret-mission-claimed')
            ->data(['url' => route('missions')]);
    }
}
