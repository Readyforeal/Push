<?php

use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskKind;
use App\Models\PromptLibrary;
use App\Models\PromptTemplate;
use App\Models\Relationship;
use App\Models\RelationshipPromptSchedule;
use App\Models\User;
use App\Services\DailyPromptScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

test('it schedules one prompt per local day and advances through the catalog', function () {
    [$relationship, $firstUser, $secondUser] = dailyRelationship();
    $firstTemplate = dailyTemplate('shared-one', PromptRoundKind::SharedQuestion, 1);
    $secondTemplate = dailyTemplate('unique-two', PromptRoundKind::UniqueQuestions, 2, 'A different question for partner two?');
    $scheduler = app(DailyPromptScheduler::class);
    $firstDay = CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Chicago');

    $firstRound = $scheduler->scheduleFor($relationship, $firstDay);

    expect($firstRound)->not->toBeNull()
        ->and($firstRound->prompt_template_id)->toBe($firstTemplate->id)
        ->and($firstRound->scheduled_for?->toDateString())->toBe('2026-09-15')
        ->and($firstRound->tasks)->toHaveCount(2)
        ->and($firstRound->tasks->pluck('user_id')->all())->toBe([$firstUser->id, $secondUser->id])
        ->and($scheduler->scheduleFor($relationship, $firstDay->addHours(2)))->toBeNull()
        ->and($scheduler->scheduleFor($relationship, $firstDay->addDay()))->toBeNull();

    $firstRound->update(['status' => PromptRoundStatus::Revealed, 'revealed_at' => $firstDay->addHours(3)]);
    $secondRound = $scheduler->scheduleFor($relationship, $firstDay->addDay());

    expect($secondRound)->not->toBeNull()
        ->and($secondRound->prompt_template_id)->toBe($secondTemplate->id)
        ->and($secondRound->scheduled_for?->toDateString())->toBe('2026-09-16')
        ->and($secondRound->tasks->firstWhere('user_id', $secondUser->id)?->prompt)
        ->toBe('A different question for partner two?');
});

test('an unfinished prompt remains active across missed days', function () {
    [$relationship] = dailyRelationship();
    dailyTemplate('persistent-prompt', PromptRoundKind::SharedQuestion, 1);
    $scheduler = app(DailyPromptScheduler::class);
    $firstDay = CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Chicago');
    $round = $scheduler->scheduleFor($relationship, $firstDay);

    expect($scheduler->scheduleFor($relationship, $firstDay->addDays(5)))->toBeNull()
        ->and($relationship->rounds()->where('status', PromptRoundStatus::Active)->sole()->is($round))->toBeTrue()
        ->and($relationship->rounds()->count())->toBe(1);
});

test('daily prompts wait until the relationship local delivery hour', function () {
    [$relationship] = dailyRelationship();
    dailyTemplate('morning-prompt', PromptRoundKind::SharedQuestion, 1);
    $scheduler = app(DailyPromptScheduler::class);
    $early = CarbonImmutable::parse('2026-09-15 08:59:00', 'America/Chicago');

    expect($scheduler->scheduleFor($relationship, $early))->toBeNull()
        ->and($scheduler->scheduleFor($relationship, $early, force: true))->not->toBeNull();
});

test('photo favorite rounds give both partners an upload and a pick', function () {
    [$relationship, $firstUser, $secondUser] = dailyRelationship();
    dailyTemplate('reciprocal-photo', PromptRoundKind::PhotoPicker, 1, 'Pick one.');
    $scheduler = app(DailyPromptScheduler::class);
    $firstDay = CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Chicago');
    $round = $scheduler->scheduleFor($relationship, $firstDay);

    expect($round?->tasks)->toHaveCount(4)
        ->and($round?->tasks->where('kind', PromptTaskKind::PhotoUpload)->pluck('user_id')->all())
        ->toBe([$firstUser->id, $secondUser->id])
        ->and($round?->tasks->where('kind', PromptTaskKind::PhotoPick)->pluck('user_id')->all())
        ->toBe([$secondUser->id, $firstUser->id]);
});

test('photo request rounds move from requester to photographer and back', function () {
    [$relationship, $firstUser, $secondUser] = dailyRelationship();
    dailyTemplate('photo-request', PromptRoundKind::PhotoRequest, 1, 'Take three photos for me.', primaryUser: $secondUser);
    $scheduler = app(DailyPromptScheduler::class);
    $date = CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Chicago');
    $round = $scheduler->scheduleFor($relationship, $date);

    expect($round?->tasks)->toHaveCount(3)
        ->and($round?->tasks[0]->kind)->toBe(PromptTaskKind::Question)
        ->and($round?->tasks[0]->user_id)->toBe($secondUser->id)
        ->and($round?->tasks[1]->kind)->toBe(PromptTaskKind::PhotoUpload)
        ->and($round?->tasks[1]->user_id)->toBe($firstUser->id)
        ->and($round?->tasks[1]->depends_on_task_id)->toBe($round?->tasks[0]->id)
        ->and($round?->tasks[2]->kind)->toBe(PromptTaskKind::PhotoPick)
        ->and($round?->tasks[2]->user_id)->toBe($secondUser->id)
        ->and($round?->tasks[2]->depends_on_task_id)->toBe($round?->tasks[1]->id);
});

test('custom weekly slots run in time order and allow multiple rounds in a day', function () {
    [$relationship] = dailyRelationship();
    $morningLibrary = dailyLibrary('custom-morning-library', PromptRoundKind::SharedQuestion);
    $eveningLibrary = dailyLibrary('custom-evening-library', PromptRoundKind::UniqueQuestions);
    dailyTemplate('custom-morning', PromptRoundKind::SharedQuestion, 1, library: $morningLibrary);
    dailyTemplate('custom-evening', PromptRoundKind::UniqueQuestions, 2, 'Evening question two?', $eveningLibrary);
    $morning = customSlot($relationship, $morningLibrary, 2, '08:00', 1);
    $evening = customSlot($relationship, $eveningLibrary, 2, '18:00', 2);
    $scheduler = app(DailyPromptScheduler::class);
    $morningTime = CarbonImmutable::parse('2026-09-15 09:00:00', 'America/Chicago');

    $firstRound = $scheduler->scheduleFor($relationship, $morningTime);

    expect($firstRound?->relationship_prompt_schedule_id)->toBe($morning->id);

    $firstRound?->update(['status' => PromptRoundStatus::Revealed, 'revealed_at' => $morningTime]);

    expect($scheduler->scheduleFor($relationship, $morningTime->addHours(2)))->toBeNull();

    $secondRound = $scheduler->scheduleFor($relationship, $morningTime->setTime(19, 0));

    expect($secondRound?->relationship_prompt_schedule_id)->toBe($evening->id)
        ->and($relationship->rounds()->whereDate('scheduled_for', '2026-09-15')->count())->toBe(2);
});

test('a scheduled library randomly draws every unused prompt before repeating', function () {
    [$relationship] = dailyRelationship();
    $library = dailyLibrary('random-connection-library', PromptRoundKind::SharedQuestion);
    $templates = collect([
        dailyTemplate('random-one', PromptRoundKind::SharedQuestion, 1, library: $library),
        dailyTemplate('random-two', PromptRoundKind::SharedQuestion, 2, library: $library),
        dailyTemplate('random-three', PromptRoundKind::SharedQuestion, 3, library: $library),
    ]);
    customSlot($relationship, $library, 2, '09:00', 1);
    $scheduler = app(DailyPromptScheduler::class);
    $date = CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Chicago');
    $selectedIds = [];

    foreach (range(0, 2) as $week) {
        $round = $scheduler->scheduleFor($relationship, $date->addWeeks($week));
        $selectedIds[] = $round?->prompt_template_id;
        $round?->update(['status' => PromptRoundStatus::Revealed, 'revealed_at' => $date->addWeeks($week)]);
    }

    expect($selectedIds)->toHaveCount(3)
        ->and(array_unique($selectedIds))->toHaveCount(3)
        ->and($templates->pluck('id')->sort()->values()->all())
        ->toBe(collect($selectedIds)->sort()->values()->all());
});

test('a scheduled library waits when it has no curated prompts', function () {
    [$relationship] = dailyRelationship();
    $library = dailyLibrary('empty-curated-library', PromptRoundKind::SharedQuestion);
    customSlot($relationship, $library, 2, '09:00', 1);

    $round = app(DailyPromptScheduler::class)->scheduleFor(
        $relationship,
        CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Chicago'),
    );

    expect($round)->toBeNull()
        ->and($relationship->rounds()->count())->toBe(0);
});

/** @return array{Relationship, User, User} */
function dailyRelationship(): array
{
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$firstUser->id, $secondUser->id], ['joined_at' => now()]);

    return [$relationship, $firstUser, $secondUser];
}

function dailyTemplate(
    string $slug,
    PromptRoundKind $kind,
    int $position,
    ?string $secondaryPrompt = null,
    ?PromptLibrary $library = null,
    ?User $primaryUser = null,
): PromptTemplate {
    return PromptTemplate::query()->create([
        'prompt_library_id' => $library?->id,
        'primary_user_id' => $primaryUser?->id,
        'slug' => $slug,
        'kind' => $kind,
        'primary_prompt' => 'What would you like to share today?',
        'secondary_prompt' => $secondaryPrompt,
        'active' => true,
        'position' => $position,
    ]);
}

function customSlot(
    Relationship $relationship,
    PromptLibrary $library,
    int $dayOfWeek,
    string $deliveryTime,
    int $position,
): RelationshipPromptSchedule {
    return $relationship->promptSchedules()->create([
        'prompt_library_id' => $library->id,
        'day_of_week' => $dayOfWeek,
        'delivery_time' => $deliveryTime,
        'position' => $position,
        'active' => true,
    ]);
}

function dailyLibrary(string $slug, PromptRoundKind $kind): PromptLibrary
{
    return PromptLibrary::query()->create([
        'name' => str($slug)->headline(),
        'slug' => $slug,
        'kind' => $kind,
        'active' => true,
    ]);
}
