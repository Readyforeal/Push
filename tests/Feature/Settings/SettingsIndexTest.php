<?php

use App\Models\User;

test('guests are redirected from the settings index', function () {
    $this->get(route('settings.index'))->assertRedirect(route('login'));
});

test('settings index displays each settings destination', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSee('Settings')
        ->assertSee(route('profile.edit'), false)
        ->assertSee(route('security.edit'), false)
        ->assertSee(route('relationship.edit'), false)
        ->assertSee(route('prompt-schedule.edit'), false)
        ->assertSee(route('prompt-libraries.edit'), false)
        ->assertSee(route('prompt-temperature.edit'), false)
        ->assertSee(route('activity-log.index'), false)
        ->assertSee(route('notifications.edit'), false)
        ->assertSee(route('appearance.edit'), false);
});

test('prompt administration settings are hidden from non administrators', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.index'))
        ->assertOk()
        ->assertDontSee(route('prompt-schedule.edit'), false)
        ->assertDontSee(route('prompt-libraries.edit'), false);

    $this->actingAs($user)->get(route('prompt-temperature.edit'))->assertForbidden();

    $this->actingAs($user)->get(route('activity-log.index'))->assertForbidden();

    $this->actingAs($user)->get(route('prompt-schedule.edit'))->assertForbidden();
    $this->actingAs($user)->get(route('prompt-libraries.edit'))->assertForbidden();
});

test('settings detail pages link back to the settings index', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSee(route('settings.index'), false);
});
