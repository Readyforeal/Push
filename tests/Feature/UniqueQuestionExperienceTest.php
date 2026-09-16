<?php

use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskKind;
use App\Models\PromptRound;
use App\Models\Relationship;
use App\Models\User;
use App\Services\PromptRoundWorkflow;
use Livewire\Livewire;

test('partners receive different private questions and reveal both results together', function () {
    [$firstUser, $secondUser, $round] = uniqueQuestionRound();

    $this->actingAs($firstUser);
    Livewire::test('current-prompt')
        ->assertSee('Just for you')
        ->assertSee('What do you admire about your partner?')
        ->assertDontSee('How does your partner make you feel supported?')
        ->set('answer', 'Their patience and sense of humor.')
        ->call('submitAnswer')
        ->assertHasNoErrors()
        ->assertSee('Waiting for your partner')
        ->assertDontSee('How does your partner make you feel supported?');

    expect($round->fresh()->status)->toBe(PromptRoundStatus::Active);

    $this->actingAs($secondUser);
    Livewire::test('current-prompt')
        ->assertSee('Just for you')
        ->assertSee('How does your partner make you feel supported?')
        ->assertDontSee('What do you admire about your partner?')
        ->set('answer', 'They listen before trying to fix things.')
        ->call('submitAnswer')
        ->assertHasNoErrors()
        ->assertSee('Your questions, revealed')
        ->assertSee('What do you admire about your partner?')
        ->assertSee('Their patience and sense of humor.')
        ->assertSee('How does your partner make you feel supported?')
        ->assertSee('They listen before trying to fix things.');

    expect($round->fresh()->status)->toBe(PromptRoundStatus::Revealed);

    $this->get(route('history'))
        ->assertOk()
        ->assertSee('Different questions')
        ->assertSee('Their patience and sense of humor.')
        ->assertSee('They listen before trying to fix things.');
});

/** @return array{User, User, PromptRound} */
function uniqueQuestionRound(): array
{
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$firstUser->id, $secondUser->id], ['joined_at' => now()]);
    $round = app(PromptRoundWorkflow::class)->startRound($relationship, PromptRoundKind::UniqueQuestions, [
        [
            'user' => $firstUser,
            'kind' => PromptTaskKind::Question,
            'prompt' => 'What do you admire about your partner?',
        ],
        [
            'user' => $secondUser,
            'kind' => PromptTaskKind::Question,
            'prompt' => 'How does your partner make you feel supported?',
        ],
    ]);

    return [$firstUser, $secondUser, $round];
}
