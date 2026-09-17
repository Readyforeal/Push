<?php

namespace App\Services;

use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskStatus;
use App\Models\PromptRoundTask;
use App\Models\Relationship;
use App\Models\User;
use App\Notifications\PromptReminderNotification;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class PromptReminderScheduler
{
    public const REMINDER_HOUR = 21;

    public function remind(Relationship $relationship, ?DateTimeInterface $at = null): int
    {
        $now = CarbonImmutable::instance($at ?? now());
        $localNow = $now->setTimezone($relationship->timezone);

        if ($localNow->hour < self::REMINDER_HOUR) {
            return 0;
        }

        $dueTasks = PromptRoundTask::query()
            ->whereHas('round', fn ($query) => $query
                ->where('relationship_id', $relationship->id)
                ->where('status', PromptRoundStatus::Active))
            ->where('status', PromptTaskStatus::Active)
            ->whereNull('submitted_at')
            ->with('round')
            ->get()
            ->filter(fn (PromptRoundTask $task): bool => $task->reminded_at === null
                || $task->reminded_at->setTimezone($relationship->timezone)->toDateString() !== $localNow->toDateString())
            ->groupBy('user_id');

        $sent = 0;

        foreach ($dueTasks as $userId => $tasks) {
            $taskIds = $tasks->pluck('id');

            $queued = DB::transaction(function () use ($taskIds, $userId, $now, $localNow, $relationship): bool {
                $lockedTasks = PromptRoundTask::query()
                    ->whereKey($taskIds)
                    ->where('status', PromptTaskStatus::Active)
                    ->whereNull('submitted_at')
                    ->lockForUpdate()
                    ->with('round')
                    ->get()
                    ->filter(fn (PromptRoundTask $task): bool => $task->reminded_at === null
                        || $task->reminded_at->setTimezone($relationship->timezone)->toDateString() !== $localNow->toDateString());

                if ($lockedTasks->isEmpty()) {
                    return false;
                }

                PromptRoundTask::query()
                    ->whereKey($lockedTasks->pluck('id'))
                    ->update(['reminded_at' => $now]);

                $user = User::query()->find($userId);

                if (! $user) {
                    return false;
                }

                $url = $lockedTasks->count() === 1
                    ? route('prompts.show', $lockedTasks->first()->round)
                    : route('dashboard');

                $user->notify(new PromptReminderNotification($lockedTasks->count(), $url));

                return true;
            });

            if ($queued) {
                $sent++;
            }
        }

        return $sent;
    }
}
