<?php

use App\Models\Relationship;
use App\Models\TemperatureCheckIn;
use App\Models\User;
use Livewire\Livewire;

test('a user can log temperature check-ins and their partner sees the latest one', function () {
    $firstUser = User::factory()->create(['name' => 'Alex']);
    $secondUser = User::factory()->create(['name' => 'Sam']);
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$firstUser->id, $secondUser->id], ['joined_at' => now()]);

    $this->actingAs($firstUser);
    Livewire::test('temperature-gauge')
        ->assertSee('How are you feeling?')
        ->assertSeeHtml('data-temperature-card')
        ->assertSeeHtml('data-temperature-shell')
        ->assertSeeHtml('data-temperature-progress')
        ->assertSee('Sam’s temperature has not been shared')
        ->set('temperature', 8)
        ->call('logTemperature')
        ->assertHasNoErrors()
        ->assertSee('Warm')
        ->set('temperature', 9)
        ->call('logTemperature')
        ->assertHasNoErrors()
        ->assertSee('Glowing');

    expect($relationship->temperatureCheckIns()->count())->toBe(2)
        ->and($relationship->temperatureCheckIns()->latest('id')->firstOrFail()->value)->toBe(9);

    $this->actingAs($secondUser);
    Livewire::test('temperature-gauge')
        ->assertSee('Alex’s temperature: 9 out of 10, Glowing')
        ->assertSeeHtml('style="width: 90%"')
        ->assertSee('Glowing');
});

test('temperature check-ins must stay within the one to ten scale', function () {
    $user = User::factory()->create();
    $partner = User::factory()->create();
    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$user->id, $partner->id], ['joined_at' => now()]);

    $this->actingAs($user);
    Livewire::test('temperature-gauge')
        ->set('temperature', 11)
        ->call('logTemperature')
        ->assertHasErrors(['temperature']);

    expect(TemperatureCheckIn::query()->count())->toBe(0);
});
