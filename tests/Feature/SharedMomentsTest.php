<?php

use App\Models\Relationship;
use App\Models\SharedMoment;
use App\Models\SharedMomentComment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;

test('partners can log and view shared moments with an intensity note and photos', function () {
    Storage::fake('homelab_cloud');

    $author = User::factory()->create(['name' => 'Alex']);
    $partner = User::factory()->create(['name' => 'Sam']);
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$author->id, $partner->id], ['joined_at' => now()]);

    $this->actingAs($author);
    Livewire::test('shared-moments')
        ->assertSee('Keep something from your day')
        ->set('intensity', 8)
        ->set('body', 'A small moment worth remembering.')
        ->set('photos', [
            UploadedFile::fake()->image('first.jpg'),
            UploadedFile::fake()->image('second.jpg'),
        ])
        ->call('logMoment')
        ->assertHasNoErrors()
        ->assertSee('A small moment worth remembering.')
        ->assertSee('Strong · 8');

    $moment = SharedMoment::query()->with('photos')->sole();

    expect($moment->relationship_id)->toBe($relationship->id)
        ->and($moment->user_id)->toBe($author->id)
        ->and($moment->intensity)->toBe(8)
        ->and($moment->photos)->toHaveCount(2);

    foreach ($moment->photos as $photo) {
        expect($photo->disk)->toBe('homelab_cloud');
        Storage::disk('homelab_cloud')->assertExists($photo->path);
    }

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Extracurriculars')
        ->assertSee('Latest post')
        ->assertSeeInOrder(['Latest post', 'Ready for your first prompt'])
        ->assertSee(route('moment-photos.show', $moment->photos->first()), false)
        ->assertDontSee('Keep something from your day');

    $this->get(route('moments'))
        ->assertOk()
        ->assertSee('All posts')
        ->assertSee('A small moment worth remembering.');

    $this->get(route('moments.show', $moment))
        ->assertOk()
        ->assertSee('A small moment worth remembering.')
        ->assertSee(route('moment-photos.show', $moment->photos->first()), false);

    $this->actingAs($partner);
    Livewire::test('shared-moments')
        ->assertSee('Alex')
        ->assertSee('A small moment worth remembering.')
        ->assertSee('Strong · 8');
});

test('non jpeg photos are converted in temporary storage before a post is submitted', function () {
    if (! extension_loaded('imagick') || Imagick::queryFormats('PNG') === []) {
        $this->markTestSkipped('ImageMagick with PNG support is required.');
    }

    Storage::fake('homelab_cloud');
    $author = User::factory()->create();

    $this->actingAs($author);
    $component = Livewire::test('shared-moments')
        ->set('body', 'A prepared photo.')
        ->set('photos', [UploadedFile::fake()->image('camera-roll.png', 1200, 900)])
        ->assertHasNoErrors();

    $prepared = $component->get('photos')[0];
    $previewUrl = $prepared->temporaryUrl();

    expect($prepared)
        ->toBeInstanceOf(TemporaryUploadedFile::class)
        ->and($prepared->getMimeType())->toBe('image/jpeg')
        ->and($prepared->getClientOriginalName())->toBe('camera-roll.png')
        ->and($previewUrl)->toBeString();

    $this->get($previewUrl)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    $component->call('logMoment')->assertHasNoErrors();

    $photo = SharedMoment::query()->sole()->photos()->sole();

    expect($photo->mime_type)->toBe('image/jpeg')
        ->and($photo->path)->toEndWith('.jpg');
});

test('jpeg uploads are resized and compressed before reaching permanent storage', function () {
    if (! extension_loaded('imagick') || Imagick::queryFormats('JPEG') === []) {
        $this->markTestSkipped('ImageMagick with JPEG support is required.');
    }

    config([
        'services.photo.max_dimension' => 800,
        'services.photo.jpeg_quality' => 75,
    ]);
    Storage::fake('homelab_cloud');
    $author = User::factory()->create();

    $this->actingAs($author);
    $component = Livewire::test('shared-moments')
        ->set('body', 'An optimized photo.')
        ->set('photos', [UploadedFile::fake()->image('large-camera-photo.jpg', 1600, 1200)])
        ->assertHasNoErrors();

    $prepared = $component->get('photos')[0];
    $dimensions = getimagesize($prepared->getRealPath());
    $preparedSize = $prepared->getSize();

    expect($dimensions)
        ->not->toBeFalse()
        ->and($dimensions[0])->toBe(800)
        ->and($dimensions[1])->toBe(600);

    $component->call('logMoment')->assertHasNoErrors();

    $photo = SharedMoment::query()->sole()->photos()->sole();

    expect($photo->size)->toBe($preparedSize)
        ->and($photo->mime_type)->toBe('image/jpeg');
});

test('a raw photo uses its embedded jpeg preview before a post is submitted', function () {
    if (! extension_loaded('imagick') || Imagick::queryFormats('JPEG') === []) {
        $this->markTestSkipped('ImageMagick with JPEG support is required.');
    }

    Storage::fake('homelab_cloud');
    $author = User::factory()->create();
    $sourcePath = tempnam(sys_get_temp_dir(), 'push-dng-test-');
    $previewPath = tempnam(sys_get_temp_dir(), 'push-dng-preview-test-');
    $exiftoolPath = tempnam(sys_get_temp_dir(), 'push-exiftool-test-');
    $image = new Imagick;
    $image->newImage(1200, 900, 'violet');
    $image->setImageFormat('jpeg');
    $image->setImageCompressionQuality(90);
    $image->writeImage($previewPath);
    $image->clear();
    $image->destroy();
    file_put_contents($sourcePath, 'simulated Apple ProRAW payload');
    file_put_contents($exiftoolPath, "#!/bin/sh\nexec cat ".escapeshellarg($previewPath)."\n");
    chmod($exiftoolPath, 0755);
    config(['services.photo.exiftool_binary' => $exiftoolPath]);

    try {
        $upload = UploadedFile::fake()->createWithContent('apple-raw.dng', file_get_contents($sourcePath));

        $this->actingAs($author);
        $component = Livewire::test('shared-moments')
            ->set('body', 'An iPhone export with its original filename.')
            ->set('photos', [$upload])
            ->assertHasNoErrors();

        $prepared = $component->get('photos')[0];

        expect($prepared)
            ->toBeInstanceOf(TemporaryUploadedFile::class)
            ->and($prepared->getMimeType())->toBe('image/jpeg')
            ->and($prepared->getClientOriginalName())->toBe('apple-raw.dng');

        $component->call('logMoment')->assertHasNoErrors();

        $photo = SharedMoment::query()->sole()->photos()->sole();

        expect($photo->mime_type)->toBe('image/jpeg')
            ->and($photo->path)->toEndWith('.jpg');
    } finally {
        @unlink($sourcePath);
        @unlink($previewPath);
        @unlink($exiftoolPath);
    }
});

test('a raw photo cannot create a zero photo post when preview extraction is unavailable', function () {
    if (! extension_loaded('imagick')) {
        $this->markTestSkipped('ImageMagick is required.');
    }

    Storage::fake('homelab_cloud');
    config(['services.photo.exiftool_binary' => '/missing/push-exiftool']);
    $author = User::factory()->create();
    $upload = UploadedFile::fake()->createWithContent('apple-raw.dng', 'simulated Apple ProRAW payload');

    $this->actingAs($author);
    Livewire::test('shared-moments')
        ->set('body', 'A post that must retain its selected photo.')
        ->set('photos', [$upload])
        ->assertHasErrors('photos')
        ->call('logMoment')
        ->assertHasErrors('photos');

    expect(SharedMoment::query()->count())->toBe(0);
});

test('guests cannot visit the moments page', function () {
    $this->get(route('moments'))->assertRedirect(route('login'));
});

test('an unpaired user can save a private personal moment', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('shared-moments')
        ->assertSee('Create posts for yourself now.')
        ->set('body', 'A personal moment before pairing.')
        ->call('logMoment')
        ->assertHasNoErrors()
        ->assertSee('A personal moment before pairing.');

    $moment = SharedMoment::query()->sole();

    expect($moment->relationship_id)->toBeNull()
        ->and($moment->user_id)->toBe($user->id);
});

test('shared moment photos stay private to relationship members', function () {
    Storage::fake('local');

    $author = User::factory()->create();
    $partner = User::factory()->create();
    $outsider = User::factory()->create();
    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$author->id, $partner->id], ['joined_at' => now()]);
    $moment = $relationship->sharedMoments()->create([
        'user_id' => $author->id,
        'intensity' => 5,
        'body' => 'Private moment.',
    ]);
    Storage::disk('local')->put('moments/private.jpg', 'private image');
    $photo = $moment->photos()->create([
        'disk' => 'local',
        'path' => 'moments/private.jpg',
        'original_name' => 'private.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 13,
        'position' => 1,
    ]);

    $this->actingAs($author)
        ->get(route('moment-photos.show', $photo))
        ->assertOk()
        ->assertHeader('Cache-Control', 'immutable, max-age=604800, private');
    $this->actingAs($partner)->get(route('moment-photos.show', $photo))->assertOk();
    $this->actingAs($outsider)->get(route('moment-photos.show', $photo))->assertForbidden();
    $this->actingAs($author)->get(route('moments.show', $moment))->assertOk();
    $this->actingAs($partner)->get(route('moments.show', $moment))->assertOk();
    $this->actingAs($outsider)->get(route('moments.show', $moment))->assertForbidden();
});

test('personal moment history becomes visible to both partners after pairing', function () {
    Storage::fake('local');
    $author = User::factory()->create();
    $futurePartner = User::factory()->create();
    $outsider = User::factory()->create();
    $moment = SharedMoment::query()->create([
        'relationship_id' => null,
        'user_id' => $author->id,
        'intensity' => 5,
        'body' => 'Personal before pairing.',
    ]);
    Storage::disk('local')->put('moments/personal.jpg', 'private image');
    $photo = $moment->photos()->create([
        'disk' => 'local',
        'path' => 'moments/personal.jpg',
        'original_name' => 'personal.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 13,
        'position' => 1,
    ]);

    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$author->id, $futurePartner->id], ['joined_at' => now()]);

    $this->actingAs($author)->get(route('moment-photos.show', $photo))->assertOk();
    $this->actingAs($futurePartner)->get(route('moment-photos.show', $photo))->assertOk();
    $this->actingAs($outsider)->get(route('moment-photos.show', $photo))->assertForbidden();

    $this->actingAs($author);
    Livewire::test('shared-moments', ['showFeed' => true])
        ->assertSee('Personal before pairing.');

    $this->actingAs($futurePartner);
    Livewire::test('shared-moments', ['showFeed' => true])
        ->assertSee('Personal before pairing.');

    $this->actingAs($outsider);
    Livewire::test('shared-moments', ['showFeed' => true])
        ->assertDontSee('Personal before pairing.');
});

test('shared moment fields stay within their supported limits', function () {
    $user = User::factory()->create();
    $partner = User::factory()->create();
    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$user->id, $partner->id], ['joined_at' => now()]);

    $this->actingAs($user);
    Livewire::test('shared-moments')
        ->set('intensity', 0)
        ->set('body', str_repeat('a', 5001))
        ->call('logMoment')
        ->assertHasErrors(['intensity', 'body']);

    expect(SharedMoment::query()->count())->toBe(0);
});

test('the moments archive includes the complete history', function () {
    $user = User::factory()->create();
    $partner = User::factory()->create();
    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$user->id, $partner->id], ['joined_at' => now()]);

    foreach (range(1, 25) as $position) {
        $relationship->sharedMoments()->create([
            'user_id' => $user->id,
            'intensity' => 5,
            'body' => "Archive moment {$position}",
            'created_at' => now()->subDays(25 - $position),
        ]);
    }

    $this->actingAs($user);
    Livewire::test('shared-moments', ['showFeed' => true])
        ->assertSee('25 posts')
        ->assertSee('Archive moment 1')
        ->assertSee('Archive moment 25');
});

test('moment authors can edit their posts while partners cannot', function () {
    $author = User::factory()->create(['name' => 'Alex']);
    $partner = User::factory()->create(['name' => 'Sam']);
    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$author->id, $partner->id], ['joined_at' => now()]);
    $moment = $relationship->sharedMoments()->create([
        'user_id' => $author->id,
        'intensity' => 4,
        'body' => 'The original note.',
    ]);

    $this->actingAs($author);
    Livewire::test('pages::moments.show', ['moment' => $moment])
        ->assertSet('editIntensity', 4)
        ->assertSet('editBody', 'The original note.')
        ->set('editIntensity', 9)
        ->set('editBody', 'The updated note.')
        ->call('updateMoment')
        ->assertHasNoErrors()
        ->assertSee('The updated note.')
        ->assertSee('Intense · 9');

    expect($moment->fresh()->body)->toBe('The updated note.')
        ->and($moment->fresh()->intensity)->toBe(9);

    $this->actingAs($partner);
    Livewire::test('pages::moments.show', ['moment' => $moment])
        ->set('editBody', 'A change from the partner.')
        ->call('updateMoment')
        ->assertForbidden();

    Livewire::test('pages::moments.show', ['moment' => $moment])
        ->call('deleteMoment')
        ->assertForbidden();

    expect($moment->fresh())->not->toBeNull();
});

test('partners can comment and comment authors can edit or delete their own comments', function () {
    $author = User::factory()->create(['name' => 'Alex']);
    $partner = User::factory()->create(['name' => 'Sam']);
    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$author->id, $partner->id], ['joined_at' => now()]);
    $moment = $relationship->sharedMoments()->create([
        'user_id' => $author->id,
        'intensity' => 6,
        'body' => 'A day worth keeping.',
    ]);

    $this->actingAs($partner);
    Livewire::test('pages::moments.show', ['moment' => $moment])
        ->set('commentBody', 'I loved this part of our day.')
        ->call('addComment')
        ->assertHasNoErrors()
        ->assertSee('I loved this part of our day.');

    $comment = SharedMomentComment::query()->sole();

    Livewire::test('pages::moments.show', ['moment' => $moment])
        ->call('startEditingComment', $comment->id)
        ->assertSet('editingCommentBody', 'I loved this part of our day.')
        ->set('editingCommentBody', 'I really loved this part of our day.')
        ->call('updateComment')
        ->assertHasNoErrors()
        ->assertSee('I really loved this part of our day.');

    $this->actingAs($author);
    Livewire::test('pages::moments.show', ['moment' => $moment])
        ->assertSee('I really loved this part of our day.')
        ->call('deleteComment', $comment->id)
        ->assertForbidden();

    $this->actingAs($partner);
    Livewire::test('pages::moments.show', ['moment' => $moment])
        ->call('deleteComment', $comment->id)
        ->assertHasNoErrors();

    expect(SharedMomentComment::query()->count())->toBe(0);
});

test('deleting a moment removes its comments photo records and stored files', function () {
    Storage::fake('local');
    $author = User::factory()->create();
    $partner = User::factory()->create();
    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$author->id, $partner->id], ['joined_at' => now()]);
    $moment = $relationship->sharedMoments()->create([
        'user_id' => $author->id,
        'intensity' => 7,
        'body' => 'Delete me.',
    ]);
    Storage::disk('local')->put('moments/delete-me.jpg', 'image');
    $moment->photos()->create([
        'disk' => 'local',
        'path' => 'moments/delete-me.jpg',
        'position' => 1,
    ]);
    $moment->comments()->create([
        'user_id' => $partner->id,
        'body' => 'This will be removed too.',
    ]);

    $this->actingAs($author);
    Livewire::test('shared-moments', ['showFeed' => true])
        ->call('deleteMoment', $moment->id)
        ->assertHasNoErrors();

    expect(SharedMoment::query()->count())->toBe(0)
        ->and(SharedMomentComment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing('moments/delete-me.jpg');
});
