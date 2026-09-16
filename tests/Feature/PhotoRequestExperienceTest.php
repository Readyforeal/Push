<?php

use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskKind;
use App\Models\Relationship;
use App\Models\User;
use App\Notifications\PhotoPickedNotification;
use App\Notifications\PromptReadyNotification;
use App\Services\PromptRoundWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('a photo request moves from the requester to the photographer and back for a favorite', function () {
    Storage::fake('local');
    Notification::fake();

    $requester = User::factory()->create(['name' => 'Jamie']);
    $photographer = User::factory()->create(['name' => 'Taylor']);
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$requester->id, $photographer->id], ['joined_at' => now()]);
    $round = app(PromptRoundWorkflow::class)->startRound($relationship, PromptRoundKind::PhotoRequest, [
        [
            'user' => $requester,
            'kind' => PromptTaskKind::Question,
            'prompt' => 'What would you like to see?',
        ],
        [
            'user' => $photographer,
            'kind' => PromptTaskKind::PhotoUpload,
            'prompt' => 'Send what they would like to see.',
            'depends_on' => 0,
        ],
        [
            'user' => $requester,
            'kind' => PromptTaskKind::PhotoPick,
            'prompt' => 'Choose your favorite response.',
            'depends_on' => 1,
        ],
    ]);

    $this->actingAs($requester);
    Livewire::test('current-prompt')
        ->assertSee('What would you like to see?')
        ->set('answer', 'Show me the coziest corner of your day.')
        ->call('submitAnswer')
        ->assertHasNoErrors()
        ->assertSee('Your partner is taking photos');

    $this->actingAs($photographer);
    Livewire::test('current-prompt')
        ->assertSee('They’d like to see')
        ->assertSee('Show me the coziest corner of your day.')
        ->set('photos', [
            UploadedFile::fake()->image('window.jpg'),
            UploadedFile::fake()->image('chair.jpg'),
            UploadedFile::fake()->image('coffee.jpg'),
        ])
        ->call('submitPhotos')
        ->assertHasNoErrors()
        ->assertSee('Photos sent');

    $pickTask = $round->tasks()->where('kind', PromptTaskKind::PhotoPick)->firstOrFail();
    $favorite = $round->tasks()
        ->where('kind', PromptTaskKind::PhotoUpload)
        ->firstOrFail()
        ->photos()
        ->where('position', 2)
        ->firstOrFail();

    $this->actingAs($requester);
    Livewire::test('current-prompt')
        ->assertSee('Your request is ready')
        ->assertSee('Show me the coziest corner of your day.')
        ->set('selectedPhotoId', $favorite->id)
        ->call('submitSelection')
        ->assertHasNoErrors()
        ->assertSee('Request fulfilled')
        ->assertSee('Show me the coziest corner of your day.');

    expect($round->fresh()->status)->toBe(PromptRoundStatus::Revealed)
        ->and($pickTask->fresh()->photoSelection->round_photo_id)->toBe($favorite->id);

    Notification::assertSentTo($photographer, PromptReadyNotification::class, fn (PromptReadyNotification $notification) => $notification->title === 'Jamie sent a photo request'
        && $notification->body === 'Show me the coziest corner of your day.');
    Notification::assertSentTo($requester, PromptReadyNotification::class, fn (PromptReadyNotification $notification) => $notification->title === 'Photos are ready to choose');
    Notification::assertSentTo($photographer, PhotoPickedNotification::class, fn (PhotoPickedNotification $notification) => $notification->partnerName === 'Jamie');
});
