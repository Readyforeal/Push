<?php

namespace App\Listeners;

use App\Events\TemperatureCheckInCreated;
use App\Notifications\TemperatureUpdatedNotification;

class SendTemperatureUpdatedNotification
{
    public function handle(TemperatureCheckInCreated $event): void
    {
        $checkIn = $event->checkIn->loadMissing(['user', 'relationship.members']);

        foreach ($checkIn->relationship->members as $member) {
            if (! $member->is($checkIn->user)) {
                $member->notify(new TemperatureUpdatedNotification(
                    $checkIn->user->firstName(),
                    $checkIn->value,
                ));
            }
        }
    }
}
