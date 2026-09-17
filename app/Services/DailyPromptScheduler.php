<?php

namespace App\Services;

use App\Enums\PromptPhotoRequirement;
use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundOrigin;
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
        private PromptTemperaturePolicy $temperaturePolicy,
    ) {}

    public function scheduleFor(
        Relationship $relationship,
        ?DateTimeInterface $at = null,
        bool $force = false,
    ): ?PromptRound {
        $localNow = CarbonImmutable::instance($at ?? now())->setTimezone($relationship->timezone);
        $scheduledFor = $localNow->toDateString();

        if ($relationship->rounds()
            ->where('status', PromptRoundStatus::Active)
            ->where('origin', PromptRoundOrigin::Scheduled)
            ->exists()) {
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

        if (! $template || (! $library && ! $this->temperaturePolicy->allows($relationship, $template))) {
            return null;
        }

        $kind = $library ? $library->kind : $template->kind;
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

    public function randomTemplateForLibrary(
        Relationship $relationship,
        PromptLibrary $library,
    ): ?PromptTemplate {
        /** @var Collection<int, PromptTemplate> $templates */
        $templates = PromptTemplate::query()
            ->where('prompt_library_id', $library->id)
            ->where('active', true)
            ->where(function ($query) use ($relationship): void {
                $query->whereNull('relationship_id')
                    ->orWhere('relationship_id', $relationship->id);
            })
            ->get();
        $templates = $this->temperaturePolicy->filter($relationship, $templates);

        if ($templates->isEmpty()) {
            return null;
        }

        $usageCounts = $relationship->rounds()
            ->whereNotNull('prompt_template_id')
            ->selectRaw('prompt_template_id, COUNT(*) as usage_count')
            ->groupBy('prompt_template_id')
            ->pluck('usage_count', 'prompt_template_id');
        $unused = $templates
            ->whereNotIn('id', $usageCounts->keys())
            ->shuffle()
            ->first();

        if ($unused) {
            return $unused;
        }

        $candidateIds = $templates->pluck('id');

        $minimumUsage = $candidateIds
            ->map(fn ($id) => (int) ($usageCounts[$id] ?? 0))
            ->min();
        $leastUsedIds = $candidateIds
            ->filter(fn ($id) => (int) ($usageCounts[$id] ?? 0) === $minimumUsage);

        return $templates
            ->whereIn('id', $leastUsedIds)
            ->shuffle()
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

        $templates = $this->temperaturePolicy->filter($relationship, $templates);

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
    public function tasksFor(Relationship $relationship, PromptTemplate $template, array $members): array
    {
        return $this->tasksForPrompts(
            $template->kind,
            $template->primary_prompt,
            $template->secondary_prompt,
            $members,
            $template->primary_user_id,
            $template->photo_requirement,
        );
    }

    /**
     * @param  list<User>  $members
     * @return list<array{user: User, kind: PromptTaskKind, prompt: string, depends_on?: int}>
     */
    private function tasksForPrompts(
        PromptRoundKind $kind,
        string $primaryPrompt,
        ?string $secondaryPrompt,
        array $members,
        ?int $primaryUserId = null,
        PromptPhotoRequirement $photoRequirement = PromptPhotoRequirement::None,
    ): array {
        if ($kind === PromptRoundKind::SharedQuestion) {
            return [
                ['user' => $members[0], 'kind' => PromptTaskKind::Question, 'prompt' => $primaryPrompt, 'payload' => ['requires_photos' => $photoRequirement->includesPrimary()]],
                ['user' => $members[1], 'kind' => PromptTaskKind::Question, 'prompt' => $primaryPrompt, 'payload' => ['requires_photos' => $photoRequirement->includesSecondary()]],
            ];
        }

        if ($kind === PromptRoundKind::UniqueQuestions) {
            [$primaryUser, $secondaryUser] = $this->assignedMembers($members, $primaryUserId);

            return [
                ['user' => $primaryUser, 'kind' => PromptTaskKind::Question, 'prompt' => $primaryPrompt, 'payload' => ['requires_photos' => $photoRequirement->includesPrimary()]],
                ['user' => $secondaryUser, 'kind' => PromptTaskKind::Question, 'prompt' => $secondaryPrompt ?? $primaryPrompt, 'payload' => ['requires_photos' => $photoRequirement->includesSecondary()]],
            ];
        }

        if ($kind === PromptRoundKind::PhotoRequest) {
            [$requester, $sender] = $this->assignedMembers($members, $primaryUserId);

            return [
                ['user' => $requester, 'kind' => PromptTaskKind::Question, 'prompt' => $primaryPrompt, 'payload' => ['requires_photos' => $photoRequirement->includesPrimary()]],
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
    private function assignedMembers(array $members, ?int $primaryUserId): array
    {
        $primary = collect($members)->firstWhere('id', $primaryUserId) ?? $members[0];
        $secondary = collect($members)->first(fn (User $member): bool => $member->id !== $primary->id);

        return [$primary, $secondary];
    }

    /** @return list<User> */
    public function membersFor(Relationship $relationship): array
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
