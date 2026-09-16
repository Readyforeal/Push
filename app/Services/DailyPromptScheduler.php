<?php

namespace App\Services;

use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskKind;
use App\Models\PromptLibrary;
use App\Models\PromptRound;
use App\Models\PromptTemplate;
use App\Models\Relationship;
use App\Models\RelationshipPromptSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DailyPromptScheduler
{
    public const DELIVERY_HOUR = 9;

    public function __construct(
        private PromptRoundWorkflow $workflow,
    ) {}

    public function scheduleFor(
        Relationship $relationship,
        ?DateTimeInterface $at = null,
        bool $force = false,
    ): ?PromptRound {
        $localNow = CarbonImmutable::instance($at ?? now())->setTimezone($relationship->timezone);
        $scheduledFor = $localNow->toDateString();

        if ($relationship->rounds()->where('status', PromptRoundStatus::Active)->exists()) {
            return null;
        }

        $members = $this->membersFor($relationship);
        $schedule = null;
        $library = null;
        $template = null;

        if ($relationship->promptSchedules()->where('active', true)->exists()) {
            $schedule = $this->nextDueSchedule($relationship, $localNow, $force);
            $library = $schedule?->library;
            $template = $library ? null : $schedule?->template;
        } else {
            if (! $force && $localNow->hour < self::DELIVERY_HOUR) {
                return null;
            }

            if ($relationship->rounds()->whereDate('scheduled_for', $scheduledFor)->exists()) {
                return null;
            }

            $template = $this->nextTemplateFor($relationship);
        }

        if (count($members) !== 2) {
            return null;
        }

        if ($library) {
            $template = $this->randomTemplateForLibrary($relationship, $library);
        }

        if (! $template) {
            return null;
        }

        $kind = $library?->kind ?? $template->kind;
        $tasks = $this->tasksFor($relationship, $template, $members);

        return $this->workflow->startRound(
            relationship: $relationship,
            kind: $kind,
            tasks: $tasks,
            availableAt: $localNow->utc(),
            template: $template,
            schedule: $schedule,
            scheduledFor: $scheduledFor,
            library: $library ?? $template->library()->first(),
            promptSource: 'curated',
        );
    }

    private function nextDueSchedule(
        Relationship $relationship,
        CarbonImmutable $localNow,
        bool $force,
    ): ?RelationshipPromptSchedule {
        $completedScheduleIds = $relationship->rounds()
            ->whereDate('scheduled_for', $localNow->toDateString())
            ->whereNotNull('relationship_prompt_schedule_id')
            ->pluck('relationship_prompt_schedule_id');

        return $relationship->promptSchedules()
            ->with(['library', 'template'])
            ->where('active', true)
            ->where('day_of_week', $localNow->dayOfWeek)
            ->when(! $force, fn ($query) => $query->where('delivery_time', '<=', $localNow->format('H:i:s')))
            ->whereNotIn('id', $completedScheduleIds)
            ->orderBy('delivery_time')
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    private function randomTemplateForLibrary(
        Relationship $relationship,
        PromptLibrary $library,
    ): ?PromptTemplate {
        $query = PromptTemplate::query()
            ->where('prompt_library_id', $library->id)
            ->where('active', true)
            ->where(function ($query) use ($relationship): void {
                $query->whereNull('relationship_id')
                    ->orWhere('relationship_id', $relationship->id);
            });
        $usageCounts = $relationship->rounds()
            ->whereNotNull('prompt_template_id')
            ->selectRaw('prompt_template_id, COUNT(*) as usage_count')
            ->groupBy('prompt_template_id')
            ->pluck('usage_count', 'prompt_template_id');
        $unused = (clone $query)
            ->whereNotIn('id', $usageCounts->keys())
            ->inRandomOrder()
            ->first();

        if ($unused) {
            return $unused;
        }

        $candidateIds = $query->pluck('id');

        if ($candidateIds->isEmpty()) {
            return null;
        }

        $minimumUsage = $candidateIds
            ->map(fn ($id) => (int) ($usageCounts[$id] ?? 0))
            ->min();
        $leastUsedIds = $candidateIds
            ->filter(fn ($id) => (int) ($usageCounts[$id] ?? 0) === $minimumUsage);

        return PromptTemplate::query()
            ->whereIn('id', $leastUsedIds)
            ->inRandomOrder()
            ->first();
    }

    private function nextTemplateFor(Relationship $relationship): ?PromptTemplate
    {
        /** @var Collection<int, PromptTemplate> $templates */
        $templates = PromptTemplate::query()
            ->where('active', true)
            ->where(function ($query) use ($relationship): void {
                $query->whereNull('relationship_id')
                    ->orWhere('relationship_id', $relationship->id);
            })
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($templates->isEmpty()) {
            return null;
        }

        $lastUsedRoundIds = $relationship->rounds()
            ->whereNotNull('prompt_template_id')
            ->selectRaw('prompt_template_id, MAX(id) as last_round_id')
            ->groupBy('prompt_template_id')
            ->pluck('last_round_id', 'prompt_template_id');

        return $templates
            ->sortBy(fn (PromptTemplate $template) => (int) ($lastUsedRoundIds[$template->id] ?? 0))
            ->first();
    }

    /**
     * @param  list<User>  $members
     * @return list<array{user: User, kind: PromptTaskKind, prompt: string, depends_on?: int}>
     */
    private function tasksFor(Relationship $relationship, PromptTemplate $template, array $members): array
    {
        return $this->tasksForPrompts(
            $relationship,
            $template->kind,
            $template->primary_prompt,
            $template->secondary_prompt,
            $members,
        );
    }

    /**
     * @param  list<User>  $members
     * @return list<array{user: User, kind: PromptTaskKind, prompt: string, depends_on?: int}>
     */
    private function tasksForPrompts(
        Relationship $relationship,
        PromptRoundKind $kind,
        string $primaryPrompt,
        ?string $secondaryPrompt,
        array $members,
    ): array {
        if ($kind === PromptRoundKind::SharedQuestion) {
            return [
                ['user' => $members[0], 'kind' => PromptTaskKind::Question, 'prompt' => $primaryPrompt],
                ['user' => $members[1], 'kind' => PromptTaskKind::Question, 'prompt' => $primaryPrompt],
            ];
        }

        if ($kind === PromptRoundKind::UniqueQuestions) {
            return [
                ['user' => $members[0], 'kind' => PromptTaskKind::Question, 'prompt' => $primaryPrompt],
                ['user' => $members[1], 'kind' => PromptTaskKind::Question, 'prompt' => $secondaryPrompt ?? $primaryPrompt],
            ];
        }

        if ($kind === PromptRoundKind::PhotoRequest) {
            [$requester, $sender] = $this->photoRequestRolesFor($relationship, $members);

            return [
                ['user' => $requester, 'kind' => PromptTaskKind::Question, 'prompt' => $primaryPrompt],
                [
                    'user' => $sender,
                    'kind' => PromptTaskKind::PhotoUpload,
                    'prompt' => $secondaryPrompt ?? 'Take three photos for your partner.',
                    'depends_on' => 0,
                ],
                [
                    'user' => $requester,
                    'kind' => PromptTaskKind::PhotoPick,
                    'prompt' => 'Choose your favorite response.',
                    'depends_on' => 1,
                ],
            ];
        }

        return [
            ['user' => $members[0], 'kind' => PromptTaskKind::PhotoUpload, 'prompt' => $primaryPrompt],
            ['user' => $members[1], 'kind' => PromptTaskKind::PhotoUpload, 'prompt' => $primaryPrompt],
            [
                'user' => $members[1],
                'kind' => PromptTaskKind::PhotoPick,
                'prompt' => $secondaryPrompt ?? 'Pick your favorite photo.',
                'depends_on' => 0,
            ],
            [
                'user' => $members[0],
                'kind' => PromptTaskKind::PhotoPick,
                'prompt' => $secondaryPrompt ?? 'Pick your favorite photo.',
                'depends_on' => 1,
            ],
        ];
    }

    /**
     * @param  list<User>  $members
     * @return array{User, User}
     */
    private function photoRequestRolesFor(Relationship $relationship, array $members): array
    {
        $lastRequesterId = $relationship->rounds()
            ->where('kind', PromptRoundKind::PhotoRequest)
            ->latest('id')
            ->first()
            ?->tasks()
            ->where('kind', PromptTaskKind::Question)
            ->value('user_id');

        if ($lastRequesterId === $members[0]->id) {
            return [$members[1], $members[0]];
        }

        return [$members[0], $members[1]];
    }

    /** @return list<User> */
    private function membersFor(Relationship $relationship): array
    {
        $memberIds = DB::table('relationship_members')
            ->where('relationship_id', $relationship->id)
            ->orderBy('id')
            ->pluck('user_id');
        $members = [];

        foreach ($memberIds as $memberId) {
            $members[] = User::query()->findOrFail((int) $memberId);
        }

        return $members;
    }
}
