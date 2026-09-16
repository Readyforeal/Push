<?php

use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskKind;
use App\Enums\PromptTaskStatus;
use App\Events\PromptQuestionAnswered;
use App\Events\PromptRoundRevealed;
use App\Events\PromptRoundTaskActivated;
use App\Models\PromptRound;
use App\Models\Relationship;
use App\Models\RoundPhoto;
use App\Models\User;
use App\Services\PromptRoundWorkflow;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

test('question answers stay private until every task is submitted', function () {
    Event::fake();
    [$firstUser, $secondUser, $round] = questionRound();
    $workflow = app(PromptRoundWorkflow::class);
    $firstTask = $round->tasks->firstWhere('user_id', $firstUser->id);
    $secondTask = $round->tasks->firstWhere('user_id', $secondUser->id);

    $workflow->saveQuestionDraft($firstTask, $firstUser, 'A draft answer');

    expect($firstTask->fresh()->status)->toBe(PromptTaskStatus::Active)
        ->and($round->fresh()->status)->toBe(PromptRoundStatus::Active);

    $workflow->submitQuestion($firstTask, $firstUser, 'My final answer');

    expect($firstTask->fresh()->status)->toBe(PromptTaskStatus::Submitted)
        ->and($firstTask->fresh()->questionResponse->answer)->toBe('My final answer')
        ->and($round->fresh()->status)->toBe(PromptRoundStatus::Active)
        ->and($round->fresh()->revealed_at)->toBeNull()
        ->and(Gate::forUser($firstUser)->allows('view', $firstTask->fresh()->questionResponse))->toBeTrue()
        ->and(Gate::forUser($secondUser)->allows('view', $firstTask->fresh()->questionResponse))->toBeFalse();
    Event::assertNotDispatched(PromptRoundRevealed::class);
    Event::assertDispatched(PromptQuestionAnswered::class, fn (PromptQuestionAnswered $event) => $event->round->is($round)
        && $event->answeredBy->is($firstUser));

    $workflow->submitQuestion($secondTask, $secondUser, 'Their final answer');

    expect($round->fresh()->status)->toBe(PromptRoundStatus::Revealed)
        ->and($round->fresh()->revealed_at)->not->toBeNull()
        ->and(Gate::forUser($secondUser)->allows('view', $firstTask->fresh()->questionResponse))->toBeTrue();
    Event::assertDispatched(PromptRoundRevealed::class, fn (PromptRoundRevealed $event) => $event->round->is($round)
        && $event->completedBy->is($secondUser));
    Event::assertDispatchedTimes(PromptQuestionAnswered::class, 1);
});

test('a photo round unlocks the picker only after three photos are submitted', function () {
    Event::fake();
    $uploader = User::factory()->create();
    $picker = User::factory()->create();
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$uploader->id, $picker->id], ['joined_at' => now()]);
    $workflow = app(PromptRoundWorkflow::class);
    $round = $workflow->startRound($relationship, PromptRoundKind::PhotoPicker, [
        [
            'user' => $uploader,
            'kind' => PromptTaskKind::PhotoUpload,
            'prompt' => 'Choose three photos.',
        ],
        [
            'user' => $picker,
            'kind' => PromptTaskKind::PhotoPick,
            'prompt' => 'Pick your favorite.',
            'depends_on' => 0,
        ],
    ]);
    $uploadTask = $round->tasks->firstWhere('kind', PromptTaskKind::PhotoUpload);
    $pickTask = $round->tasks->firstWhere('kind', PromptTaskKind::PhotoPick);

    foreach (range(1, 3) as $position) {
        $uploadTask->photos()->create([
            'path' => "rounds/example/photo-{$position}.jpg",
            'position' => $position,
        ]);
    }

    $firstPhoto = $uploadTask->photos()->firstOrFail();
    expect(Gate::forUser($picker)->allows('view', $firstPhoto))->toBeFalse();

    $workflow->submitPhotos($uploadTask, $uploader);

    expect($uploadTask->fresh()->status)->toBe(PromptTaskStatus::Submitted)
        ->and($pickTask->fresh()->status)->toBe(PromptTaskStatus::Active)
        ->and($pickTask->fresh()->activated_at)->not->toBeNull()
        ->and($round->fresh()->status)->toBe(PromptRoundStatus::Active)
        ->and(Gate::forUser($picker)->allows('view', $firstPhoto))->toBeTrue();
    Event::assertDispatched(PromptRoundTaskActivated::class, fn (PromptRoundTaskActivated $event) => $event->task->is($pickTask));
    Event::assertNotDispatched(PromptRoundRevealed::class);

    /** @var RoundPhoto $favorite */
    $favorite = $uploadTask->photos()->where('position', 2)->firstOrFail();
    $workflow->selectPhoto($pickTask, $picker, $favorite);

    expect($pickTask->fresh()->status)->toBe(PromptTaskStatus::Submitted)
        ->and($pickTask->fresh()->photoSelection->round_photo_id)->toBe($favorite->id)
        ->and($round->fresh()->status)->toBe(PromptRoundStatus::Revealed);
    Event::assertDispatched(PromptRoundRevealed::class);
});

test('users cannot submit tasks assigned to their partner', function () {
    [$firstUser, $secondUser, $round] = questionRound();
    $firstTask = $round->tasks->firstWhere('user_id', $firstUser->id);

    app(PromptRoundWorkflow::class)->submitQuestion($firstTask, $secondUser, 'Not my task');
})->throws(DomainException::class, 'This task is assigned to another user.');

test('a relationship cannot start a second round while one is active', function () {
    $user = User::factory()->create();
    $relationship = Relationship::query()->create();
    $relationship->members()->attach($user, ['joined_at' => now()]);
    $workflow = app(PromptRoundWorkflow::class);
    $tasks = [[
        'user' => $user,
        'kind' => PromptTaskKind::Question,
        'prompt' => 'What made today meaningful?',
    ]];

    $workflow->startRound($relationship, PromptRoundKind::SharedQuestion, $tasks);
    $workflow->startRound($relationship, PromptRoundKind::SharedQuestion, $tasks);
})->throws(DomainException::class, 'This relationship already has an active round.');

test('photo uploads require exactly three photos', function () {
    $uploader = User::factory()->create();
    $relationship = Relationship::query()->create();
    $relationship->members()->attach($uploader, ['joined_at' => now()]);
    $round = PromptRound::query()->create([
        'relationship_id' => $relationship->id,
        'kind' => PromptRoundKind::PhotoPicker,
        'available_at' => now(),
    ]);
    $task = $round->tasks()->create([
        'user_id' => $uploader->id,
        'kind' => PromptTaskKind::PhotoUpload,
        'status' => PromptTaskStatus::Active,
        'position' => 1,
    ]);
    $task->photos()->createMany([
        ['path' => 'one.jpg', 'position' => 1],
        ['path' => 'two.jpg', 'position' => 2],
    ]);

    app(PromptRoundWorkflow::class)->submitPhotos($task, $uploader);
})->throws(DomainException::class, 'A photo prompt requires exactly three photos.');

/**
 * @return array{User, User, PromptRound}
 */
function questionRound(): array
{
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$firstUser->id, $secondUser->id], ['joined_at' => now()]);
    $round = app(PromptRoundWorkflow::class)->startRound($relationship, PromptRoundKind::UniqueQuestions, [
        [
            'user' => $firstUser,
            'kind' => PromptTaskKind::Question,
            'prompt' => 'What made you smile today?',
        ],
        [
            'user' => $secondUser,
            'kind' => PromptTaskKind::Question,
            'prompt' => 'What are you looking forward to?',
        ],
    ]);

    return [$firstUser, $secondUser, $round];
}
