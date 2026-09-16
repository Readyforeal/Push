<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class RoundResultReadyNotification extends Notification
{
    public function __construct(public string $partnerName = 'Your partner') {}

    /** @return array<int, class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("{$this->partnerName} finished today’s prompt")
            ->body('Your answers are ready—see what you shared with each other.')
            ->icon('/apple-touch-icon.png')
            ->badge('/apple-touch-icon.png')
            ->tag('round-result-ready')
            ->data(['url' => route('dashboard')]);
    }
}
