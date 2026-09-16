<?php

use App\Enums\PromptRoundKind;
use App\Enums\PromptTaskKind;
use App\Models\Relationship;
use App\Models\User;
use App\Services\PromptRoundWorkflow;
use Illuminate\Support\Facades\Notification;

test('guests cannot visit the photo library', function () {
    $this->get(route('library'))->assertRedirect(route('login'));
});

test('the library has a helpful empty state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('library'))
        ->assertOk()
        ->assertSee('Your favorites will live here');
});

test('the library only displays photos selected as relationship favorites', function () {
    Notification::fake();

    $uploader = User::factory()->create(['name' => 'Alex']);
    $picker = User::factory()->create(['name' => 'Sam']);
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$uploader->id, $picker->id], ['joined_at' => now()]);

    $round = app(PromptRoundWorkflow::class)->startRound($relationship, PromptRoundKind::PhotoPicker, [
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
    $photos = collect(range(1, 3))->map(fn (int $position) => $uploadTask->photos()->create([
        'disk' => 'local',
        'path' => "rounds/library-{$position}.jpg",
        'original_name' => "library-{$position}.jpg",
        'mime_type' => 'image/jpeg',
        'size' => 100,
        'position' => $position,
    ]));

    $workflow = app(PromptRoundWorkflow::class);
    $workflow->submitPhotos($uploadTask, $uploader);
    $workflow->selectPhoto($pickTask, $picker, $photos[1]);

    $this->actingAs($uploader)
        ->get(route('library'))
        ->assertOk()
        ->assertSee('Picked by Sam')
        ->assertSee('Shared by Alex')
        ->assertSee(route('round-photos.show', $photos[1]), false)
        ->assertDontSee(route('round-photos.show', $photos[0]), false)
        ->assertDontSee(route('round-photos.show', $photos[2]), false);
});
