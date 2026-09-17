<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class TemperatureUpdatedNotification extends WebPushNotification
{
    public function __construct(
        public string $partnerName,
        public int $temperature,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("{$this->partnerName} updated their temperature")
            ->body("They’re at {$this->temperature} out of 10 right now.")
            ->icon('/apple-touch-icon.png')
            ->badge('/apple-touch-icon.png')
            ->tag('temperature-updated')
            ->data(['url' => route('dashboard')]);
    }
}
