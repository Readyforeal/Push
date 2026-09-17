<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class PartnerJoinedNotification extends WebPushNotification
{
    public function __construct(public readonly string $partnerName) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('You’re paired!')
            ->body("{$this->partnerName} accepted your invitation.")
            ->icon('/apple-touch-icon.png')
            ->badge('/apple-touch-icon.png')
            ->tag('partner-joined')
            ->data(['url' => route('dashboard')]);
    }
}
