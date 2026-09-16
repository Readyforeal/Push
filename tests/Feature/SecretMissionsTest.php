<?php

use App\Enums\SecretMissionStatus;
use App\Models\Relationship;
use App\Models\SecretMission;
use App\Models\SecretMissionPrompt;
use App\Models\User;
use App\Notifications\SecretMissionCompletedNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
});

test('partners can build large private mission lists one at a time or by import', function () {
    [$firstUser, $secondUser, $relationship] = secretMissionRelationship();

    $this->actingAs($firstUser);
    Livewire::test('pages::missions')
        ->set('missionBody', 'Plan a cozy movie night for me.')
        ->call('addMission')
        ->assertHasNoErrors()
        ->set('bulkMissions', collect(range(1, 1000))->map(fn (int $number) => "Secret request {$number}")->implode("\n"))
        ->call('importMissions')
        ->assertHasNoErrors()
        ->assertSee('1001 missions for your partner');

    expect(SecretMissionPrompt::query()->where('beneficiary_user_id', $firstUser->id)->count())->toBe(1001);

    $this->actingAs($secondUser);
    Livewire::test('pages::missions')
        ->assertDontSee('Plan a cozy movie night for me.')
        ->assertDontSee('Secret request 1');

    expect($relationship->secretMissionPrompts()->where('beneficiary_user_id', $secondUser->id)->count())->toBe(0);
});

test('a claimed mission stays active until completion and source prompts remain reusable', function () {
    [$firstUser, $secondUser, $relationship] = secretMissionRelationship();
    $prompt = $relationship->secretMissionPrompts()->create([
        'beneficiary_user_id' => $secondUser->id,
        'body' => 'Bring me coffee in bed this weekend.',
        'active' => true,
    ]);

    $this->actingAs($firstUser);
    $component = Livewire::test('pages::missions')
        ->call('claimMission')
        ->assertHasNoErrors()
        ->assertSee('Your active mission')
        ->assertSee('Bring me coffee in bed this weekend.');

    $mission = SecretMission::query()->sole();

    expect($mission->assignee_user_id)->toBe($firstUser->id)
        ->and($mission->beneficiary_user_id)->toBe($secondUser->id)
        ->and($mission->status)->toBe(SecretMissionStatus::Active)
        ->and($prompt->fresh())->not->toBeNull();

    $component
        ->call('claimMission')
        ->assertHasErrors('mission');

    expect(SecretMission::query()->count())->toBe(1);

    $component
        ->call('completeMission', $mission->id)
        ->assertHasNoErrors()
        ->assertSee('Ready for a mission?')
        ->call('claimMission')
        ->assertHasNoErrors()
        ->assertSee('Bring me coffee in bed this weekend.');

    expect(SecretMissionPrompt::query()->count())->toBe(1)
        ->and(SecretMission::query()->count())->toBe(2)
        ->and(SecretMission::query()->where('status', SecretMissionStatus::Active)->count())->toBe(1);

    Notification::assertSentTo($secondUser, SecretMissionCompletedNotification::class);
});

test('active mission details remain hidden from the beneficiary assignment view', function () {
    [$assignee, $beneficiary, $relationship] = secretMissionRelationship();
    $prompt = $relationship->secretMissionPrompts()->create([
        'beneficiary_user_id' => $beneficiary->id,
        'body' => 'Leave me a handwritten note somewhere surprising.',
        'active' => true,
    ]);
    $mission = $relationship->secretMissions()->create([
        'secret_mission_prompt_id' => $prompt->id,
        'assignee_user_id' => $assignee->id,
        'beneficiary_user_id' => $beneficiary->id,
        'body' => $prompt->body,
        'status' => SecretMissionStatus::Active,
        'accepted_at' => now(),
    ]);

    $this->actingAs($beneficiary);
    Livewire::test('pages::missions')
        ->assertDontSee('Your active mission')
        ->assertSet('activeMission', null);

    $this->actingAs($assignee);
    Livewire::test('pages::missions')
        ->assertSee('Your active mission')
        ->assertSee($mission->body);
});

test('deleting a source prompt does not alter an already accepted mission', function () {
    [$assignee, $beneficiary, $relationship] = secretMissionRelationship();
    $prompt = $relationship->secretMissionPrompts()->create([
        'beneficiary_user_id' => $beneficiary->id,
        'body' => 'Take care of one annoying chore for me.',
        'active' => true,
    ]);

    $this->actingAs($assignee);
    Livewire::test('pages::missions')->call('claimMission')->assertHasNoErrors();
    $mission = SecretMission::query()->sole();

    $this->actingAs($beneficiary);
    Livewire::test('pages::missions')
        ->call('removeMissionPrompt', $prompt->id)
        ->assertHasNoErrors();

    expect($mission->fresh()->body)->toBe('Take care of one annoying chore for me.')
        ->and($mission->fresh()->secret_mission_prompt_id)->toBeNull();
});

test('users cannot remove mission prompts owned by their partner', function () {
    [$firstUser, $secondUser, $relationship] = secretMissionRelationship();
    $prompt = $relationship->secretMissionPrompts()->create([
        'beneficiary_user_id' => $firstUser->id,
        'body' => 'Make breakfast for me.',
        'active' => true,
    ]);

    $this->actingAs($secondUser);
    Livewire::test('pages::missions')
        ->call('removeMissionPrompt', $prompt->id)
        ->assertHasErrors('mission');

    expect($prompt->fresh())->not->toBeNull();
});

test('guests cannot visit secret missions', function () {
    $this->get(route('missions'))->assertRedirect(route('login'));
});

/** @return array{User, User, Relationship} */
function secretMissionRelationship(): array
{
    $firstUser = User::factory()->create(['name' => 'Alex']);
    $secondUser = User::factory()->create(['name' => 'Sam']);
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$firstUser->id, $secondUser->id], ['joined_at' => now()]);

    return [$firstUser, $secondUser, $relationship];
}
