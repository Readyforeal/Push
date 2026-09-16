<?php

use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskKind;
use App\Enums\PromptTaskStatus;
use App\Events\PromptPhotoSelected;
use App\Listeners\SendPhotoSelectedNotification;
use App\Models\PromptRound;
use App\Models\Relationship;
use App\Models\User;
use App\Notifications\PhotoPickedNotification;
use App\Services\PromptRoundWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('one partner uploads three photos and the other picks a favorite', function () {
    Storage::fake('homelab_cloud');
    Notification::fake();
    [$uploader, $picker, $round] = photoPickerRound();
    $photos = [
        UploadedFile::fake()->image('one.jpg'),
        UploadedFile::fake()->image('two.jpg'),
        UploadedFile::fake()->image('three.jpg'),
    ];

    $this->actingAs($uploader);
    Livewire::test('current-prompt')
        ->assertSee('Choose three photos')
        ->set('photos', $photos)
        ->call('submitPhotos')
        ->assertHasNoErrors()
        ->assertSee('Photos sent');

    $uploadTask = $round->tasks()->where('kind', PromptTaskKind::PhotoUpload)->firstOrFail();
    $pickTask = $round->tasks()->where('kind', PromptTaskKind::PhotoPick)->firstOrFail();
    $favorite = $uploadTask->photos()->where('position', 2)->firstOrFail();

    expect($uploadTask->fresh()->status)->toBe(PromptTaskStatus::Submitted)
        ->and($pickTask->fresh()->status)->toBe(PromptTaskStatus::Active)
        ->and($uploadTask->photos()->count())->toBe(3);

    foreach ($uploadTask->photos as $photo) {
        expect($photo->disk)->toBe('homelab_cloud');
        Storage::disk('homelab_cloud')->assertExists($photo->path);
    }

    $this->actingAs($picker);
    Livewire::test('current-prompt')
        ->assertSee('Pick a favorite')
        ->set('selectedPhotoId', $favorite->id)
        ->call('submitSelection')
        ->assertHasNoErrors()
        ->assertSee('Your favorites were picked')
        ->assertSee('This one was the favorite');

    expect($round->fresh()->status)->toBe(PromptRoundStatus::Revealed)
        ->and($pickTask->fresh()->photoSelection->round_photo_id)->toBe($favorite->id);

    $this->get(route('history'))
        ->assertOk()
        ->assertSee('Photo favorite')
        ->assertSee('This one was the favorite');

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('app-photo-background', false)
        ->assertSee(route('round-photos.show', $favorite), false);

    $this->actingAs($uploader)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('app-photo-background', false);
});

test('scheduled photo favorites are reciprocal for both partners', function () {
    Notification::fake();

    $firstUser = User::factory()->create(['name' => 'Alex']);
    $secondUser = User::factory()->create(['name' => 'Sam']);
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$firstUser->id, $secondUser->id], ['joined_at' => now()]);
    $workflow = app(PromptRoundWorkflow::class);
    $round = $workflow->startRound($relationship, PromptRoundKind::PhotoPicker, [
        ['user' => $firstUser, 'kind' => PromptTaskKind::PhotoUpload, 'prompt' => 'Choose three photos.'],
        ['user' => $secondUser, 'kind' => PromptTaskKind::PhotoUpload, 'prompt' => 'Choose three photos.'],
        ['user' => $secondUser, 'kind' => PromptTaskKind::PhotoPick, 'prompt' => 'Pick one.', 'depends_on' => 0],
        ['user' => $firstUser, 'kind' => PromptTaskKind::PhotoPick, 'prompt' => 'Pick one.', 'depends_on' => 1],
    ]);
    $firstUpload = $round->tasks->where('kind', PromptTaskKind::PhotoUpload)->values()[0];
    $secondUpload = $round->tasks->where('kind', PromptTaskKind::PhotoUpload)->values()[1];
    $secondPick = $round->tasks->where('kind', PromptTaskKind::PhotoPick)->values()[0];
    $firstPick = $round->tasks->where('kind', PromptTaskKind::PhotoPick)->values()[1];

    foreach ([$firstUpload, $secondUpload] as $uploadTask) {
        foreach (range(1, 3) as $position) {
            $uploadTask->photos()->create([
                'path' => "rounds/{$uploadTask->id}/photo-{$position}.jpg",
                'position' => $position,
            ]);
        }
    }

    $workflow->submitPhotos($firstUpload, $firstUser);
    $workflow->submitPhotos($secondUpload, $secondUser);

    expect($secondPick->fresh()->status)->toBe(PromptTaskStatus::Active)
        ->and($firstPick->fresh()->status)->toBe(PromptTaskStatus::Active);

    $secondFavorite = $firstUpload->photos()->where('position', 2)->firstOrFail();
    $firstFavorite = $secondUpload->photos()->where('position', 3)->firstOrFail();
    $workflow->selectPhoto($secondPick, $secondUser, $secondFavorite);

    expect($round->fresh()->status)->toBe(PromptRoundStatus::Active);

    $workflow->selectPhoto($firstPick, $firstUser, $firstFavorite);

    expect($round->fresh()->status)->toBe(PromptRoundStatus::Revealed)
        ->and($round->tasks()->whereHas('photoSelection')->count())->toBe(2);

    $this->actingAs($firstUser)
        ->get(route('library'))
        ->assertOk()
        ->assertSee(route('round-photos.show', $firstFavorite), false)
        ->assertSee(route('round-photos.show', $secondFavorite), false);

    Notification::assertSentTo($firstUser, PhotoPickedNotification::class, fn ($notification) => $notification->partnerName === 'Sam');
    Notification::assertSentTo($secondUser, PhotoPickedNotification::class, fn ($notification) => $notification->partnerName === 'Alex');
});

test('round photos are private to the couple and unavailable while the picker is locked', function () {
    Storage::fake('local');
    [$uploader, $picker, $round] = photoPickerRound();
    $outsider = User::factory()->create();
    $uploadTask = $round->tasks()->where('kind', PromptTaskKind::PhotoUpload)->firstOrFail();
    $pickTask = $round->tasks()->where('kind', PromptTaskKind::PhotoPick)->firstOrFail();
    Storage::disk('local')->put('rounds/private.jpg', 'private photo');
    $photo = $uploadTask->photos()->create([
        'disk' => 'local',
        'path' => 'rounds/private.jpg',
        'original_name' => 'private.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 13,
        'position' => 1,
    ]);

    $this->actingAs($uploader)->get(route('round-photos.show', $photo))->assertOk();
    $this->actingAs($picker)->get(route('round-photos.show', $photo))->assertForbidden();
    $this->actingAs($outsider)->get(route('round-photos.show', $photo))->assertForbidden();

    $pickTask->update(['status' => PromptTaskStatus::Active, 'activated_at' => now()]);

    $this->actingAs($picker)->get(route('round-photos.show', $photo))->assertOk();
});

test('the uploader receives a personalized notification after a favorite is picked', function () {
    Notification::fake();
    [$uploader, $picker, $round] = photoPickerRound();
    $uploadTask = $round->tasks()->where('kind', PromptTaskKind::PhotoUpload)->firstOrFail();
    $pickTask = $round->tasks()->where('kind', PromptTaskKind::PhotoPick)->firstOrFail();
    $photo = $uploadTask->photos()->create(['path' => 'rounds/favorite.jpg', 'position' => 1]);
    $selection = $pickTask->photoSelection()->create(['round_photo_id' => $photo->id]);

    app(SendPhotoSelectedNotification::class)->handle(new PromptPhotoSelected($selection, $picker));

    Notification::assertSentTo($uploader, PhotoPickedNotification::class, fn ($notification) => $notification->partnerName === $picker->name);
    Notification::assertNotSentTo($picker, PhotoPickedNotification::class);

    $notification = new PhotoPickedNotification('Taylor');

    expect($notification->toWebPush($uploader, $notification)->toArray())
        ->toMatchArray([
            'title' => 'Taylor picked a favorite',
            'body' => 'See which photo stood out to them.',
        ]);
});

/** @return array{User, User, PromptRound} */
function photoPickerRound(): array
{
    $uploader = User::factory()->create();
    $picker = User::factory()->create();
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

    return [$uploader, $picker, $round];
}
