<?php

use App\Models\User;

test('guests are redirected from the settings index', function () {
    $this->get(route('settings.index'))->assertRedirect(route('login'));
});

test('settings index displays each settings destination', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSee('Settings')
        ->assertSee(route('profile.edit'), false)
        ->assertSee(route('security.edit'), false)
        ->assertSee(route('relationship.edit'), false)
        ->assertSee(route('prompt-schedule.edit'), false)
        ->assertSee(route('prompt-libraries.edit'), false)
        ->assertSee(route('notifications.edit'), false)
        ->assertSee(route('appearance.edit'), false);
});

test('settings detail pages link back to the settings index', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSee(route('settings.index'), false);
});
