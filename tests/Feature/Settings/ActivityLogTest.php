<?php

use App\Models\PageVisit;
use App\Models\User;

test('authenticated page visits can be recorded without storing page contents', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('activity.visits.store'), ['route' => 'moments.show'])
        ->assertNoContent();

    $visit = PageVisit::query()->sole();

    expect($visit->user_id)->toBe($user->id)
        ->and($visit->route_name)->toBe('moments.show')
        ->and($visit->route_uri)->toBe('moments/{moment}');
});

test('media requests and unknown routes are not recorded as page visits', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('activity.visits.store'), ['route' => 'round-photos.show'])
        ->assertNoContent();

    $this->actingAs($user)
        ->postJson(route('activity.visits.store'), ['route' => 'not-a-real-page'])
        ->assertNoContent();

    expect(PageVisit::query()->count())->toBe(0);
});

test('only administrators can view the activity log', function () {
    $admin = User::factory()->admin()->create();
    $partner = User::factory()->create(['name' => 'Taylor Rivera']);

    PageVisit::query()->create([
        'user_id' => $partner->id,
        'route_name' => 'dashboard',
        'route_uri' => 'dashboard',
        'visited_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('activity-log.index'))
        ->assertOk()
        ->assertSee('Activity log')
        ->assertSee('Taylor Rivera')
        ->assertSee('Home');

    $this->actingAs($partner)
        ->get(route('activity-log.index'))
        ->assertForbidden();
});

test('page visits older than ninety days are pruned', function () {
    $user = User::factory()->create();

    PageVisit::query()->create([
        'user_id' => $user->id,
        'route_name' => 'dashboard',
        'route_uri' => 'dashboard',
        'visited_at' => now()->subDays(91),
    ]);
    PageVisit::query()->create([
        'user_id' => $user->id,
        'route_name' => 'moments',
        'route_uri' => 'moments',
        'visited_at' => now()->subDays(30),
    ]);

    $this->artisan('activity:prune')->assertSuccessful();

    expect(PageVisit::query()->pluck('route_name')->all())->toBe(['moments']);
});
