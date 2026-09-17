<?php

use App\Enums\PromptRoundKind;
use App\Models\PromptLibrary;
use App\Models\Relationship;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create(['name' => 'Jamie Parker']);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response
        ->assertOk()
        ->assertSee('data-mobile-dock', false)
        ->assertSee('bottom: calc(0.75rem + 4pt);', false)
        ->assertSee('data-mobile-header', false)
        ->assertSee('data-app-brand', false)
        ->assertSee('Push')
        ->assertSee('data-home-screen', false)
        ->assertSee('data-home-heading', false)
        ->assertSee('data-home-card-stack', false)
        ->assertSee(', Jamie.')
        ->assertDontSee(', Jamie Parker.')
        ->assertDontSee('A little space to slow down and stay close.')
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
        ->assertDontSee('Latest post');
});

test('extracurricular libraries show their description without prompt counts', function () {
    $user = User::factory()->create();
    $partner = User::factory()->create();
    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$user->id, $partner->id], ['joined_at' => now()]);
    $library = PromptLibrary::query()->create([
        'relationship_id' => $relationship->id,
        'name' => 'Play together',
        'slug' => 'play-together-dashboard-test',
        'description' => 'Small invitations to be silly together.',
        'kind' => PromptRoundKind::SharedQuestion,
        'active' => true,
    ]);
    $relationship->extracurricularLibraries()->attach($library);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Choose something extra and draw one prompt on demand.')
        ->assertSee('Play together')
        ->assertSee('Small invitations to be silly together.')
        ->assertDontSee('0 prompts')
        ->assertDontSee('Draw one on demand');
});
