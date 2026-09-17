<?php

namespace App\Events;

use App\Models\TemperatureCheckIn;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TemperatureCheckInCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public TemperatureCheckIn $checkIn) {}
}
