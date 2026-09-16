<?php

use App\Enums\AppBackgroundMode;
use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskKind;
use App\Enums\PromptTaskStatus;
use App\Models\PromptRound;
use App\Models\Relationship;
use App\Models\RoundPhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('a user can upload replace and remove a private app background', function () {
    Storage::fake('homelab_cloud');
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->set('backgroundUpload', UploadedFile::fake()->image('first-background.jpg', 1200, 800)->size(13 * 1024))
        ->call('saveBackground')
        ->assertHasNoErrors()
        ->assertDispatched('app-background-updated');

    $user->refresh();
    $firstPath = $user->background_image_path;

    expect($user->background_mode)->toBe(AppBackgroundMode::Upload)
        ->and($user->background_image_disk)->toBe('homelab_cloud')
        ->and($firstPath)->not->toBeNull()
        ->and(config('livewire.temporary_file_upload.rules'))->toContain('max:51200');
    Storage::disk('homelab_cloud')->assertExists($firstPath);

    $this->get(route('background.show'))
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg');

    $this->actingAs($otherUser)
        ->get(route('background.show'))
        ->assertNotFound();

    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->set('backgroundUpload', UploadedFile::fake()->image('replacement.png', 900, 1200))
        ->call('saveBackground')
        ->assertHasNoErrors();

    $user->refresh();
    Storage::disk('homelab_cloud')->assertMissing($firstPath);
    Storage::disk('homelab_cloud')->assertExists($user->background_image_path);

    $replacementPath = $user->background_image_path;

    Livewire::test('pages::settings.appearance')
        ->call('removeUploadedBackground')
        ->assertDispatched('app-background-updated');

    $user->refresh();

    expect($user->background_mode)->toBe(AppBackgroundMode::Auto)
        ->and($user->background_image_disk)->toBeNull()
        ->and($user->background_image_path)->toBeNull();
    Storage::disk('homelab_cloud')->assertMissing($replacementPath);
});

test('a user can pin a library favorite as their app background', function () {
    [$user, $partner, $relationship] = appearanceRelationship();
    $photo = appearanceFavoritePhoto($user, $partner, $relationship, 'rounds/pinned.jpg');

    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->assertSee('App background')
        ->assertSee(route('round-photos.show', $photo), false)
        ->call('choosePhoto', $photo->id)
        ->call('saveBackground')
        ->assertHasNoErrors()
        ->assertDispatched('app-background-updated')
        ->assertDispatched('background-preference-saved');

    $user->refresh();

    expect($user->background_mode)->toBe(AppBackgroundMode::Photo)
        ->and($user->background_photo_id)->toBe($photo->id);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('app-photo-background', false)
        ->assertSee(route('round-photos.show', $photo), false);
});

test('a user can disable the photo background or return to the latest favorite', function () {
    [$user, $partner, $relationship] = appearanceRelationship();
    $photo = appearanceFavoritePhoto($user, $partner, $relationship, 'rounds/latest.jpg');

    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->call('chooseMode', AppBackgroundMode::None->value)
        ->call('saveBackground')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->background_mode)->toBe(AppBackgroundMode::None)
        ->and($user->background_photo_id)->toBeNull();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('app-photo-background', false);

    Livewire::test('pages::settings.appearance')
        ->call('chooseMode', AppBackgroundMode::Auto->value)
        ->call('saveBackground')
        ->assertHasNoErrors();

    expect($user->fresh()->background_mode)->toBe(AppBackgroundMode::Auto);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('app-photo-background', false)
        ->assertSee(route('round-photos.show', $photo), false);
});

test('a user cannot use a photo outside their shared library', function () {
    [$owner, $ownerPartner, $ownerRelationship] = appearanceRelationship();
    $photo = appearanceFavoritePhoto($owner, $ownerPartner, $ownerRelationship, 'rounds/private.jpg');
    [$outsider] = appearanceRelationship();

    $this->actingAs($outsider);

    Livewire::test('pages::settings.appearance')
        ->call('choosePhoto', $photo->id)
        ->call('saveBackground')
        ->assertHasErrors('backgroundPhotoId');

    $outsider->refresh();

    expect($outsider->background_mode)->toBe(AppBackgroundMode::Auto)
        ->and($outsider->background_photo_id)->toBeNull();
});

/** @return array{User, User, Relationship} */
function appearanceRelationship(): array
{
    $user = User::factory()->create();
    $partner = User::factory()->create();
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$user->id, $partner->id], ['joined_at' => now()]);

    return [$user, $partner, $relationship];
}

function appearanceFavoritePhoto(User $picker, User $uploader, Relationship $relationship, string $path): RoundPhoto
{
    $round = PromptRound::query()->create([
        'relationship_id' => $relationship->id,
        'kind' => PromptRoundKind::PhotoPicker,
        'status' => PromptRoundStatus::Revealed,
        'available_at' => now(),
        'revealed_at' => now(),
    ]);
    $uploadTask = $round->tasks()->create([
        'user_id' => $uploader->id,
        'kind' => PromptTaskKind::PhotoUpload,
        'status' => PromptTaskStatus::Submitted,
        'position' => 1,
        'submitted_at' => now(),
    ]);
    $pickTask = $round->tasks()->create([
        'user_id' => $picker->id,
        'kind' => PromptTaskKind::PhotoPick,
        'status' => PromptTaskStatus::Submitted,
        'position' => 2,
        'submitted_at' => now(),
    ]);
    $photo = $uploadTask->photos()->create([
        'path' => $path,
        'position' => 1,
    ]);
    $pickTask->photoSelection()->create(['round_photo_id' => $photo->id]);

    return $photo;
}
