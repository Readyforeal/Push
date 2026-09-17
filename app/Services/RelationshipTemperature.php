<?php

namespace App\Services;

use App\Models\Relationship;
use App\Models\TemperatureCheckIn;

class RelationshipTemperature
{
    public const DEFAULT = 5;

    public function current(Relationship $relationship): int
    {
        $memberIds = $relationship->members()->pluck('users.id');

        if ($memberIds->isEmpty()) {
            return self::DEFAULT;
        }

        $latestCheckInIds = $relationship->temperatureCheckIns()
            ->whereIn('user_id', $memberIds)
            ->selectRaw('MAX(id)')
            ->groupBy('user_id');
        $latestValues = TemperatureCheckIn::query()
            ->whereIn('id', $latestCheckInIds)
            ->pluck('value');

        return $latestValues->isEmpty()
            ? self::DEFAULT
            : (int) $latestValues->min();
    }
}
