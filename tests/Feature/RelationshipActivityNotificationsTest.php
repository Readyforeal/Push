<?php

use App\Models\Relationship;
use App\Models\SharedMoment;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

test('posts comments and temperature updates do not send notifications', function () {
    Notification::fake();
    $author = User::factory()->create(['name' => 'Jamie Parker']);
    $partner = User::factory()->create(['name' => 'Taylor Morgan']);
    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$author->id, $partner->id], ['joined_at' => now()]);

    $moment = $relationship->sharedMoments()->create([
        'user_id' => $author->id,
        'intensity' => 7,
        'body' => 'A small piece of today.',
    ]);

    $moment->comments()->create([
        'user_id' => $partner->id,
        'body' => 'I love this.',
    ]);
    $moment->update(['body' => 'Edited without a push.']);

    $relationship->temperatureCheckIns()->create([
        'user_id' => $author->id,
        'value' => 8,
    ]);

    Notification::assertNothingSent();
});

test('an unpaired personal post does not send a notification', function () {
    Notification::fake();
    $author = User::factory()->create();

    SharedMoment::query()->create([
        'user_id' => $author->id,
        'intensity' => 5,
        'body' => 'A personal post.',
    ]);

    Notification::assertNothingSent();
});
