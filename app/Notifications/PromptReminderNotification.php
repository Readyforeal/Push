<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class PromptReminderNotification extends WebPushNotification
{
    public function __construct(
        public int $count,
        public string $url,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $title = $this->count === 1
            ? 'You have a prompt waiting'
            : "You have {$this->count} prompts waiting";

        return (new WebPushMessage)
            ->title($title)
            ->body('A little moment together is still waiting for you tonight.')
            ->icon('/apple-touch-icon.png')
            ->badge('/apple-touch-icon.png')
            ->tag('prompt-reminder')
            ->data(['url' => $this->url]);
    }
}
