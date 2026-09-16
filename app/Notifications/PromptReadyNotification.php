<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class PromptReadyNotification extends Notification
{
    public function __construct(
        public string $title = 'A new prompt is ready',
        public string $body = 'Take a moment to answer when you’re ready.',
        public string $tag = 'prompt-ready',
    ) {}

    /** @return array<int, class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/apple-touch-icon.png')
            ->badge('/apple-touch-icon.png')
            ->tag($this->tag)
            ->data(['url' => route('dashboard')]);
    }
}
