<?php

namespace App\Services;

use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundOrigin;
use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskKind;
use App\Enums\PromptTaskStatus;
use App\Events\PromptPhotoSelected;
use App\Events\PromptQuestionAnswered;
use App\Events\PromptRoundRevealed;
use App\Events\PromptRoundTaskActivated;
use App\Models\PhotoSelection;
use App\Models\PromptLibrary;
use App\Models\PromptRound;
use App\Models\PromptRoundTask;
use App\Models\PromptTemplate;
use App\Models\QuestionResponse;
use App\Models\Relationship;
use App\Models\RelationshipPromptSchedule;
use App\Models\RoundPhoto;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PromptRoundWorkflow
{
    /**
     * @param  list<array{
     *     user: User,
     *     kind: PromptTaskKind,
     *     prompt?: string,
     *     payload?: array<string, mixed>,
     *     depends_on?: int
     * }>  $tasks
     */
    public function startRound(
        Relationship $relationship,
        PromptRoundKind $kind,
        array $tasks,
        ?DateTimeInterface $availableAt = null,
        ?PromptTemplate $template = null,
        ?RelationshipPromptSchedule $schedule = null,
        ?string $scheduledFor = null,
        ?PromptLibrary $library = null,
        string $promptSource = 'curated',
        ?string $aiModel = null,
        PromptRoundOrigin $origin = PromptRoundOrigin::Scheduled,
        ?User $requestedBy = null,
    ): PromptRound {
        if ($tasks === []) {
            throw new DomainException('A prompt round must contain at least one task.');
        }

        /** @var array{round: PromptRound, activated: Collection<int, PromptRoundTask>} $result */
        $result = DB::transaction(function () use ($relationship, $kind, $tasks, $availableAt, $template, $schedule, $scheduledFor, $library, $promptSource, $aiModel, $origin, $requestedBy): array {
            $relationship = Relationship::query()->lockForUpdate()->findOrFail($relationship->id);

            if ($relationship->rounds()->where('status', PromptRoundStatus::Active)->where('origin', $origin)->exists()) {
                throw new DomainException($origin === PromptRoundOrigin::Extracurricular
                    ? 'Finish your current extracurricular prompt before requesting another.'
                    : 'This relationship already has an active round.');
            }

            $memberIds = $relationship->members()->pluck('users.id');
            $round = $relationship->rounds()->create([
                'prompt_template_id' => $template?->id,
                'prompt_library_id' => $library?->id,
                'relationship_prompt_schedule_id' => $schedule?->id,
                'kind' => $kind,
                'origin' => $origin,
                'requested_by_user_id' => $requestedBy?->id,
                'prompt_source' => $promptSource,
                'ai_model' => $aiModel,
                'status' => PromptRoundStatus::Active,
                'available_at' => $availableAt ?? now(),
                'scheduled_for' => $scheduledFor,
            ]);
            /** @var array<int, PromptRoundTask> $createdTasks */
            $createdTasks = [];
            /** @var Collection<int, PromptRoundTask> $activated */
            $activated = collect();

            foreach ($tasks as $position => $definition) {
                if (! $memberIds->contains($definition['user']->id)) {
                    throw new DomainException('Every task must be assigned to a relationship member.');
                }

                $dependencyPosition = $definition['depends_on'] ?? null;

                if ($dependencyPosition !== null && ! isset($createdTasks[$dependencyPosition])) {
                    throw new DomainException('A task can only depend on an earlier task in the same round.');
                }

                $isLocked = $dependencyPosition !== null;
                $task = $round->tasks()->create([
                    'user_id' => $definition['user']->id,
                    'depends_on_task_id' => $isLocked ? $createdTasks[$dependencyPosition]->id : null,
                    'kind' => $definition['kind'],
                    'status' => $isLocked ? PromptTaskStatus::Locked : PromptTaskStatus::Active,
                    'prompt' => $definition['prompt'] ?? null,
                    'payload' => $definition['payload'] ?? null,
                    'position' => $position + 1,
                    'activated_at' => $isLocked ? null : now(),
                ]);

                $createdTasks[$position] = $task;

                if (! $isLocked) {
                    $activated->push($task);
                }
            }

            return ['round' => $round->load('tasks'), 'activated' => $activated];
        });

        foreach ($result['activated'] as $activatedTask) {
            PromptRoundTaskActivated::dispatch($activatedTask);
        }

        return $result['round'];
    }

    public function saveQuestionDraft(PromptRoundTask $task, User $actor, string $answer): QuestionResponse
    {
        return DB::transaction(function () use ($task, $actor, $answer): QuestionResponse {
            $task = $this->lockActiveTask($task, $actor, PromptTaskKind::Question);

            return QuestionResponse::query()->updateOrCreate(
                ['prompt_round_task_id' => $task->id],
                ['answer' => $answer],
            );
        });
    }

    public function submitQuestion(PromptRoundTask $task, User $actor, string $answer): PromptRound
    {
        if (blank(trim($answer))) {
            throw new DomainException('A question response cannot be empty.');
        }

        return $this->submit($task, $actor, PromptTaskKind::Question, function (PromptRoundTask $lockedTask) use ($answer): void {
            if ($lockedTask->payload['requires_photos'] ?? false) {
                $photoCount = $lockedTask->photos()->count();

                if ($photoCount < 1 || $photoCount > 3) {
                    throw new DomainException('This prompt requires between one and three photos.');
                }
            }

            QuestionResponse::query()->updateOrCreate(
                ['prompt_round_task_id' => $lockedTask->id],
                ['answer' => $answer],
            );
        });
    }

    public function submitPhotos(PromptRoundTask $task, User $actor): PromptRound
    {
        return $this->submit($task, $actor, PromptTaskKind::PhotoUpload, function (PromptRoundTask $lockedTask): void {
            if ($lockedTask->photos()->count() !== 3) {
                throw new DomainException('A photo prompt requires exactly three photos.');
            }
        });
    }

    public function selectPhoto(PromptRoundTask $task, User $actor, RoundPhoto $photo): PromptRound
    {
        $round = $this->submit($task, $actor, PromptTaskKind::PhotoPick, function (PromptRoundTask $lockedTask) use ($photo): void {
            $photo = RoundPhoto::query()->with('task')->findOrFail($photo->id);

            if ($photo->task->prompt_round_id !== $lockedTask->prompt_round_id
                || $photo->task->kind !== PromptTaskKind::PhotoUpload) {
                throw new DomainException('The selected photo does not belong to this round.');
            }

            PhotoSelection::query()->create([
                'prompt_round_task_id' => $lockedTask->id,
                'round_photo_id' => $photo->id,
            ]);
        });

        $selection = PhotoSelection::query()
            ->where('prompt_round_task_id', $task->id)
            ->firstOrFail();
        PromptPhotoSelected::dispatch($selection, $actor);

        return $round;
    }

    /**
     * @param  callable(PromptRoundTask): void  $recordSubmission
     */
    private function submit(
        PromptRoundTask $task,
        User $actor,
        PromptTaskKind $expectedKind,
        callable $recordSubmission,
    ): PromptRound {
        /** @var array{round: PromptRound, activated: Collection<int, PromptRoundTask>, revealed: bool} $result */
        $result = DB::transaction(function () use ($task, $actor, $expectedKind, $recordSubmission): array {
            $task = $this->lockActiveTask($task, $actor, $expectedKind);
            $round = PromptRound::query()->lockForUpdate()->findOrFail($task->prompt_round_id);

            if ($round->status !== PromptRoundStatus::Active) {
                throw new DomainException('This round is no longer active.');
            }

            $recordSubmission($task);

            $task->update([
                'status' => PromptTaskStatus::Submitted,
                'submitted_at' => now(),
            ]);

            /** @var Collection<int, PromptRoundTask> $activated */
            $activated = PromptRoundTask::query()
                ->where('prompt_round_id', $round->id)
                ->where('depends_on_task_id', $task->id)
                ->where('status', PromptTaskStatus::Locked)
                ->lockForUpdate()
                ->get();

            foreach ($activated as $dependent) {
                $dependent->update([
                    'status' => PromptTaskStatus::Active,
                    'activated_at' => now(),
                ]);
            }

            $revealed = ! $round->tasks()
                ->where('status', '!=', PromptTaskStatus::Submitted)
                ->exists();

            if ($revealed) {
                $round->update([
                    'status' => PromptRoundStatus::Revealed,
                    'revealed_at' => now(),
                ]);
            }

            return [
                'round' => $round->fresh(),
                'activated' => $activated,
                'revealed' => $revealed,
            ];
        });

        foreach ($result['activated'] as $activatedTask) {
            PromptRoundTaskActivated::dispatch($activatedTask);
        }

        if (! $result['revealed']
            && $expectedKind === PromptTaskKind::Question
            && $result['round']->kind !== PromptRoundKind::PhotoRequest) {
            PromptQuestionAnswered::dispatch($result['round'], $actor);
        }

        if ($result['revealed']) {
            PromptRoundRevealed::dispatch($result['round'], $actor);
        }

        return $result['round'];
    }

    private function lockActiveTask(
        PromptRoundTask $task,
        User $actor,
        PromptTaskKind $expectedKind,
    ): PromptRoundTask {
        $task = PromptRoundTask::query()->lockForUpdate()->findOrFail($task->id);

        if ($task->user_id !== $actor->id) {
            throw new DomainException('This task is assigned to another user.');
        }

        if ($task->kind !== $expectedKind) {
            throw new DomainException('This submission does not match the task type.');
        }

        if ($task->status !== PromptTaskStatus::Active) {
            throw new DomainException('This task is not active.');
        }

        return $task;
    }
}
