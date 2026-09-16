<?php

use App\Models\User;

test('guests see the welcome page with a login invitation', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertSee('A little space to stay close.')
        ->assertSee('Log in to your space')
        ->assertSee(route('login'));
});

test('authenticated users can continue from the welcome page to their dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Open your shared space')
        ->assertSee(route('dashboard'));
});
