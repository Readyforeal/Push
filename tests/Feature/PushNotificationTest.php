<?php

use App\Models\User;
use App\Notifications\TestPushNotification;
use NotificationChannels\WebPush\WebPushChannel;

test('guests cannot manage push subscriptions', function () {
    $this->postJson(route('push.subscriptions.store'))->assertUnauthorized();
    $this->deleteJson(route('push.subscriptions.destroy'))->assertUnauthorized();
    $this->postJson(route('push.send'))->assertUnauthorized();
});

test('a user can save and remove a browser push subscription', function () {
    $user = User::factory()->create();
    $subscription = [
        'endpoint' => 'https://push.example.test/subscriptions/phone',
        'keys' => [
            'p256dh' => 'browser-public-key',
            'auth' => 'browser-auth-token',
        ],
        'contentEncoding' => 'aes128gcm',
    ];

    $this->actingAs($user)
        ->postJson(route('push.subscriptions.store'), $subscription)
        ->assertOk()
        ->assertJsonPath('message', 'This device is subscribed.');

    $this->assertDatabaseHas('push_subscriptions', [
        'subscribable_id' => $user->id,
        'subscribable_type' => User::class,
        'endpoint' => $subscription['endpoint'],
        'content_encoding' => 'aes128gcm',
    ]);

    $this->actingAs($user)
        ->deleteJson(route('push.subscriptions.destroy'), ['endpoint' => $subscription['endpoint']])
        ->assertOk();

    $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $subscription['endpoint']]);
});

test('a user with a subscription can send a test push', function () {
    $user = User::factory()->create();
    $user->updatePushSubscription(
        'https://push.example.test/subscriptions/phone',
        'browser-public-key',
        'browser-auth-token',
        'aes128gcm',
    );
    $channel = Mockery::mock(WebPushChannel::class);
    $channel->shouldReceive('send')
        ->once()
        ->with($user, Mockery::on(fn (TestPushNotification $notification) => $notification->title === 'Hello from the test'
            && $notification->body === 'This is a Web Push test.'))
        ->andReturn([]);
    $this->app->instance(WebPushChannel::class, $channel);

    $this->actingAs($user)
        ->postJson(route('push.send'), [
            'title' => 'Hello from the test',
            'body' => 'This is a Web Push test.',
        ])
        ->assertOk()
        ->assertJsonPath('subscriptions', 1);
});

test('a user without a subscription cannot send a test push', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('push.send'), [
            'title' => 'Hello',
            'body' => 'No subscribed browser yet.',
        ])
        ->assertUnprocessable();
});
