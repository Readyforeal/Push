<?php

use App\Enums\PromptRoundKind;
use App\Enums\PromptTaskKind;
use App\Models\Relationship;
use App\Models\User;
use App\Notifications\PromptReminderNotification;
use App\Services\PromptReminderScheduler;
use App\Services\PromptRoundWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

test('unanswered active prompts are reminded once per local day after 9 pm', function () {
    Notification::fake();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$first->id, $second->id], ['joined_at' => now()]);
    $round = app(PromptRoundWorkflow::class)->startRound($relationship, PromptRoundKind::SharedQuestion, [
        ['user' => $first, 'kind' => PromptTaskKind::Question, 'prompt' => 'How was today?'],
        ['user' => $second, 'kind' => PromptTaskKind::Question, 'prompt' => 'How was today?'],
    ]);
    $scheduler = app(PromptReminderScheduler::class);

    expect($scheduler->remind($relationship, CarbonImmutable::parse('2026-09-17 20:59', 'America/Chicago')))->toBe(0)
        ->and($scheduler->remind($relationship, CarbonImmutable::parse('2026-09-17 21:00', 'America/Chicago')))->toBe(2)
        ->and($scheduler->remind($relationship, CarbonImmutable::parse('2026-09-17 21:30', 'America/Chicago')))->toBe(0);

    Notification::assertSentTo($first, PromptReminderNotification::class, fn ($notification) => $notification->count === 1
        && $notification->url === route('prompts.show', $round));
    Notification::assertSentTo($second, PromptReminderNotification::class);

    app(PromptRoundWorkflow::class)->submitQuestion(
        $round->tasks()->where('user_id', $first->id)->firstOrFail(),
        $first,
        'It was lovely.',
    );

    expect($scheduler->remind($relationship, CarbonImmutable::parse('2026-09-18 21:00', 'America/Chicago')))->toBe(1);
    expect(Notification::sent($first, PromptReminderNotification::class))->toHaveCount(1)
        ->and(Notification::sent($second, PromptReminderNotification::class))->toHaveCount(2);
});
