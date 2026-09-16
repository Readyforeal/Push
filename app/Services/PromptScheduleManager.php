<?php

namespace App\Services;

use App\Models\PromptLibrary;
use App\Models\Relationship;
use App\Models\RelationshipPromptSchedule;
use App\Models\User;
use DomainException;

class PromptScheduleManager
{
    public function add(
        User $actor,
        Relationship $relationship,
        PromptLibrary $library,
        int $dayOfWeek,
        string $deliveryTime,
    ): RelationshipPromptSchedule {
        $this->authorize($actor, $relationship);

        if (! $library->active
            || ($library->relationship_id !== null && $library->relationship_id !== $relationship->id)) {
            throw new DomainException('That prompt library is not currently available.');
        }

        $position = (int) $relationship->promptSchedules()
            ->where('day_of_week', $dayOfWeek)
            ->max('position') + 1;

        return $relationship->promptSchedules()->create([
            'prompt_library_id' => $library->id,
            'prompt_template_id' => null,
            'day_of_week' => $dayOfWeek,
            'delivery_time' => $deliveryTime,
            'position' => $position,
            'active' => true,
        ]);
    }

    public function remove(
        User $actor,
        Relationship $relationship,
        RelationshipPromptSchedule $schedule,
    ): void {
        $this->authorize($actor, $relationship);

        if ($schedule->relationship_id !== $relationship->id) {
            throw new DomainException('That schedule does not belong to your relationship.');
        }

        $schedule->delete();
    }

    private function authorize(User $actor, Relationship $relationship): void
    {
        if (! $relationship->hasMember($actor)) {
            throw new DomainException('You cannot manage this relationship schedule.');
        }
    }
}
