<?php

use App\Models\User;

test('guests are redirected from notification settings', function () {
    $this->get(route('notifications.edit'))->assertRedirect(route('login'));
});

test('notification settings page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('notifications.edit'))
        ->assertOk()
        ->assertSee('Notifications')
        ->assertSee('data-push-panel', false);
});
