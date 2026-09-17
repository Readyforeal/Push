<?php

use App\Models\Relationship;
use App\Models\SharedMoment;
use App\Models\User;
use App\Notifications\PostCommentedNotification;
use App\Notifications\PostCreatedNotification;
use App\Notifications\TemperatureUpdatedNotification;
use Illuminate\Support\Facades\Notification;

test('posts and comments notify only the partner who did not create them', function () {
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

    Notification::assertSentTo($partner, PostCreatedNotification::class, fn ($notification) => $notification->partnerName === 'Jamie'
        && $notification->momentId === $moment->id);
    Notification::assertNotSentTo($author, PostCreatedNotification::class);

    $moment->comments()->create([
        'user_id' => $partner->id,
        'body' => 'I love this.',
    ]);

    Notification::assertSentTo($author, PostCommentedNotification::class, fn ($notification) => $notification->partnerName === 'Taylor'
        && $notification->momentId === $moment->id);
    Notification::assertNotSentTo($partner, PostCommentedNotification::class);

    $moment->update(['body' => 'Edited without a push.']);

    expect(Notification::sent($partner, PostCreatedNotification::class))->toHaveCount(1);
});

test('temperature check ins notify the other partner using a first name', function () {
    Notification::fake();
    $first = User::factory()->create(['name' => 'Jamie Parker']);
    $second = User::factory()->create(['name' => 'Taylor Morgan']);
    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$first->id, $second->id], ['joined_at' => now()]);

    $relationship->temperatureCheckIns()->create([
        'user_id' => $first->id,
        'value' => 8,
    ]);

    Notification::assertSentTo($second, TemperatureUpdatedNotification::class, fn ($notification) => $notification->partnerName === 'Jamie'
        && $notification->temperature === 8);
    Notification::assertNotSentTo($first, TemperatureUpdatedNotification::class);
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
