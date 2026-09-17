<?php

namespace App\Services;

use App\Enums\PromptRoundOrigin;
use App\Enums\PromptRoundStatus;
use App\Models\PromptLibrary;
use App\Models\PromptRound;
use App\Models\Relationship;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class ExtracurricularPromptManager
{
    public function __construct(
        private DailyPromptScheduler $scheduler,
        private PromptRoundWorkflow $workflow,
    ) {}

    public function request(User $actor, Relationship $relationship, PromptLibrary $library): PromptRound
    {
        if (! $relationship->hasMember($actor)
            || ! $relationship->extracurricularLibraries()->whereKey($library->id)->exists()) {
            throw new DomainException('That extracurricular prompt library is not available.');
        }

        return DB::transaction(function () use ($actor, $relationship, $library): PromptRound {
            $relationship = Relationship::query()->lockForUpdate()->findOrFail($relationship->id);
            $today = CarbonImmutable::now($relationship->timezone)->toDateString();

            $active = $relationship->rounds()
                ->where('origin', PromptRoundOrigin::Extracurricular)
                ->where('status', PromptRoundStatus::Active)
                ->first();

            if ($active) {
                return $active;
            }

            if ($relationship->rounds()
                ->where('origin', PromptRoundOrigin::Extracurricular)
                ->whereDate('scheduled_for', $today)
                ->exists()) {
                throw new DomainException('You already requested an extracurricular prompt today.');
            }

            $template = $this->scheduler->randomTemplateForLibrary($relationship, $library);

            if (! $template) {
                throw new DomainException('Add at least one active prompt to this library first.');
            }

            return $this->workflow->startRound(
                relationship: $relationship,
                kind: $library->kind,
                tasks: $this->scheduler->tasksFor($relationship, $template, $this->scheduler->membersFor($relationship)),
                availableAt: now(),
                template: $template,
                scheduledFor: $today,
                library: $library,
                promptSource: 'curated',
                origin: PromptRoundOrigin::Extracurricular,
                requestedBy: $actor,
            );
        });
    }
}
