<?php

use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskKind;
use App\Enums\PromptTaskStatus;
use App\Events\PromptQuestionAnswered;
use App\Events\PromptRoundRevealed;
use App\Events\PromptRoundTaskActivated;
use App\Listeners\SendPartnerAnsweredPromptNotification;
use App\Listeners\SendPromptReadyNotification;
use App\Listeners\SendRoundResultReadyNotification;
use App\Models\PromptRound;
use App\Models\Relationship;
use App\Models\User;
use App\Notifications\PartnerAnsweredPromptNotification;
use App\Notifications\PromptReadyNotification;
use App\Notifications\RoundResultReadyNotification;
use App\Services\PromptRoundWorkflow;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('partners answer privately and reveal a shared result together', function () {
    Notification::fake();
    [$firstUser, $secondUser, $round] = sharedQuestionRound();

    $this->actingAs($firstUser);
    Livewire::test('current-prompt')
        ->assertSee('What made you feel loved this week?')
        ->set('answer', 'You made coffee before I woke up.')
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->call('submitAnswer')
        ->assertHasNoErrors()
        ->assertSee('Waiting for your partner');

    expect($round->fresh()->status)->toBe(PromptRoundStatus::Active)
        ->and($round->tasks()->where('user_id', $firstUser->id)->firstOrFail()->status)->toBe(PromptTaskStatus::Submitted);

    $this->actingAs($secondUser);
    Livewire::test('current-prompt')
        ->set('answer', 'You checked in when my day was hard.')
        ->call('submitAnswer')
        ->assertHasNoErrors()
        ->assertSee('Your round result')
        ->assertSee('You made coffee before I woke up.')
        ->assertSee('You checked in when my day was hard.');

    expect($round->fresh()->status)->toBe(PromptRoundStatus::Revealed);

    $this->get(route('history'))
        ->assertOk()
        ->assertSee('You made coffee before I woke up.')
        ->assertSee('You checked in when my day was hard.');
});

test('prompt progress and result events notify the waiting partner', function () {
    Notification::fake();
    [$firstUser, $secondUser, $round] = sharedQuestionRound();
    $task = $round->tasks()->where('user_id', $firstUser->id)->firstOrFail();

    app(SendPromptReadyNotification::class)->handle(new PromptRoundTaskActivated($task));
    app(SendPartnerAnsweredPromptNotification::class)->handle(new PromptQuestionAnswered($round, $firstUser));
    app(SendRoundResultReadyNotification::class)->handle(new PromptRoundRevealed($round, $secondUser));

    Notification::assertSentTo($firstUser, PromptReadyNotification::class);
    Notification::assertSentTo($secondUser, PartnerAnsweredPromptNotification::class, fn ($notification) => $notification->partnerName === $firstUser->firstName());
    Notification::assertNotSentTo($firstUser, PartnerAnsweredPromptNotification::class);
    Notification::assertSentTo($firstUser, RoundResultReadyNotification::class, fn ($notification) => $notification->partnerName === $secondUser->firstName());
    Notification::assertNotSentTo($secondUser, RoundResultReadyNotification::class);
});

test('partner progress notifications use warm personalized copy', function () {
    $user = User::factory()->create();
    $progress = new PartnerAnsweredPromptNotification('Taylor');
    $result = new RoundResultReadyNotification('Taylor');

    expect($progress->toWebPush($user, $progress)->toArray())
        ->toMatchArray([
            'title' => 'Taylor answered today’s prompt',
            'body' => 'Your turn—share your answer when you’re ready.',
        ])
        ->and($result->toWebPush($user, $result)->toArray())
        ->toMatchArray([
            'title' => 'Taylor finished today’s prompt',
            'body' => 'Your answers are ready—see what you shared with each other.',
        ]);
});

/** @return array{User, User, PromptRound} */
function sharedQuestionRound(): array
{
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$firstUser->id, $secondUser->id], ['joined_at' => now()]);
    $round = app(PromptRoundWorkflow::class)->startRound($relationship, PromptRoundKind::SharedQuestion, [
        [
            'user' => $firstUser,
            'kind' => PromptTaskKind::Question,
            'prompt' => 'What made you feel loved this week?',
        ],
        [
            'user' => $secondUser,
            'kind' => PromptTaskKind::Question,
            'prompt' => 'What made you feel loved this week?',
        ],
    ]);

    return [$firstUser, $secondUser, $round];
}
