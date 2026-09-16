<?php

use App\Models\Relationship;
use App\Models\SharedMoment;
use App\Models\SharedMomentComment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        ->assertSee('Latest moment')
        ->assertSee(route('moment-photos.show', $moment->photos->first()), false)
        ->assertSee(route('moments'), false);

    $this->get(route('moments'))
        ->assertOk()
        ->assertSee('Recent moments')
        ->assertSee('A small moment worth remembering.');

    $this->actingAs($partner);
    Livewire::test('shared-moments')
        ->assertSee('Alex')
        ->assertSee('A small moment worth remembering.')
        ->assertSee('Strong · 8');
});

test('guests cannot visit the moments page', function () {
    $this->get(route('moments'))->assertRedirect(route('login'));
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

    $this->actingAs($author)->get(route('moment-photos.show', $photo))->assertOk();
    $this->actingAs($partner)->get(route('moment-photos.show', $photo))->assertOk();
    $this->actingAs($outsider)->get(route('moment-photos.show', $photo))->assertForbidden();
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
    Livewire::test('shared-moments', ['showFeed' => true])
        ->call('startEditingMoment', $moment->id)
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
    Livewire::test('shared-moments', ['showFeed' => true])
        ->call('startEditingMoment', $moment->id)
        ->assertForbidden();

    Livewire::test('shared-moments', ['showFeed' => true])
        ->call('deleteMoment', $moment->id)
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
    Livewire::test('shared-moments', ['showFeed' => true])
        ->set("commentBodies.{$moment->id}", 'I loved this part of our day.')
        ->call('addComment', $moment->id)
        ->assertHasNoErrors()
        ->assertSee('I loved this part of our day.');

    $comment = SharedMomentComment::query()->sole();

    Livewire::test('shared-moments', ['showFeed' => true])
        ->call('startEditingComment', $comment->id)
        ->assertSet('editingCommentBody', 'I loved this part of our day.')
        ->set('editingCommentBody', 'I really loved this part of our day.')
        ->call('updateComment')
        ->assertHasNoErrors()
        ->assertSee('I really loved this part of our day.');

    $this->actingAs($author);
    Livewire::test('shared-moments', ['showFeed' => true])
        ->assertSee('I really loved this part of our day.')
        ->call('deleteComment', $comment->id)
        ->assertForbidden();

    $this->actingAs($partner);
    Livewire::test('shared-moments', ['showFeed' => true])
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
