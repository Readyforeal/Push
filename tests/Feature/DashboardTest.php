<?php

use App\Models\Relationship;
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
        ->assertSee('data-home-screen', false)
        ->assertSee('data-home-card-stack', false)
        ->assertSee('data-dock-item="dashboard"', false)
        ->assertSeeInOrder([
            'data-dock-item="dashboard"',
            'data-dock-item="history"',
            'data-dock-item="moments"',
            'data-dock-item="library"',
        ], false)
        ->assertSee(route('moments'))
        ->assertSee(route('library'))
        ->assertDontSee('data-dock-item="missions"', false)
        ->assertDontSee('data-dock-item="profile.edit"', false)
        ->assertDontSee(route('notifications.edit'));
});

test('paired users see secret missions in extracurriculars instead of the moments preview', function () {
    $user = User::factory()->create();
    $partner = User::factory()->create();
    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$user->id, $partner->id], ['joined_at' => now()]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Extracurriculars')
        ->assertSee('Secret Missions')
        ->assertSee(route('missions'))
        ->assertDontSee('Latest moment');
});
