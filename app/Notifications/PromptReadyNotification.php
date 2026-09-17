<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class PromptReadyNotification extends WebPushNotification
{
    public function __construct(
        public string $title = 'A new prompt is ready',
        public string $body = 'Take a moment to answer when you’re ready.',
        public string $tag = 'prompt-ready',
        public ?string $url = null,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/apple-touch-icon.png')
            ->badge('/apple-touch-icon.png')
            ->tag($this->tag)
            ->data(['url' => $this->url ?? route('dashboard')]);
    }
}
