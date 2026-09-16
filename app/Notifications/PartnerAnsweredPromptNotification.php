<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class PartnerAnsweredPromptNotification extends Notification
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
            ->title("{$this->partnerName} answered today’s prompt")
            ->body('Your turn—share your answer when you’re ready.')
            ->icon('/apple-touch-icon.png')
            ->badge('/apple-touch-icon.png')
            ->tag('partner-answered-prompt')
            ->data(['url' => route('dashboard')]);
    }
}
