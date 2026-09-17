<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class ExtracurricularReadyNotification extends WebPushNotification
{
    public function __construct(
        public string $partnerName,
        public int $roundId,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("{$this->partnerName} finished their part")
            ->body('There’s an extracurricular waiting for you too.')
            ->icon('/apple-touch-icon.png')
            ->badge('/apple-touch-icon.png')
            ->tag("extracurricular-ready-{$this->roundId}")
            ->data(['url' => route('prompts.show', $this->roundId)]);
    }
}
