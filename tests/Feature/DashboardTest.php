<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response
        ->assertOk()
        ->assertSee('data-mobile-dock', false)
        ->assertSee('data-dock-item="dashboard"', false)
        ->assertSeeInOrder([
            'data-dock-item="dashboard"',
            'data-dock-item="history"',
            'data-dock-item="moments"',
            'data-dock-item="missions"',
            'data-dock-item="library"',
        ], false)
        ->assertSee(route('moments'))
        ->assertSee(route('library'))
        ->assertDontSee('data-dock-item="profile.edit"', false)
        ->assertDontSee(route('notifications.edit'));
});
