<?php

use App\Enums\PromptRoundKind;
use App\Models\PromptLibrary;
use App\Models\PromptTemplate;
use App\Models\Relationship;
use App\Models\User;
use App\Services\PromptScheduleManager;
use Livewire\Livewire;

test('a paired user can build and edit the shared weekly prompt schedule', function () {
    [$user, $relationship] = scheduleSettingsRelationship();
    $library = PromptLibrary::query()->create([
        'name' => 'Everyday connection',
        'slug' => 'settings-everyday-connection',
        'kind' => PromptRoundKind::SharedQuestion,
        'active' => true,
    ]);
    PromptTemplate::query()->create([
        'prompt_library_id' => $library->id,
        'slug' => 'settings-shared-question',
        'kind' => PromptRoundKind::SharedQuestion,
        'primary_prompt' => 'What made you feel close today?',
        'active' => true,
        'position' => 1,
    ]);

    $this->actingAs($user);
    $component = Livewire::test('pages::settings.prompt-schedule')
        ->assertSee('Prompt schedule')
        ->assertSee('Monday')
        ->assertSee('automatic daily rotation')
        ->call('openAddPrompt', 1)
        ->set('promptLibraryId', $library->id)
        ->set('deliveryTime', '19:30')
        ->call('addPrompt')
        ->assertHasNoErrors()
        ->assertSee('Everyday connection')
        ->assertSee('7:30 PM');

    $schedule = $relationship->promptSchedules()->sole();

    expect($schedule->day_of_week)->toBe(1)
        ->and($schedule->delivery_time)->toBe('19:30');

    $component->call('removePrompt', $schedule->id)
        ->assertHasNoErrors()
        ->assertSee('No prompts scheduled');

    $this->assertDatabaseMissing('relationship_prompt_schedules', ['id' => $schedule->id]);
});

test('a user cannot change another relationships schedule', function () {
    [$member, $relationship] = scheduleSettingsRelationship();
    $outsider = User::factory()->admin()->create();
    $library = PromptLibrary::query()->create([
        'name' => 'Private schedule',
        'slug' => 'private-schedule-library',
        'kind' => PromptRoundKind::SharedQuestion,
        'active' => true,
    ]);
    $schedule = $relationship->promptSchedules()->create([
        'prompt_library_id' => $library->id,
        'day_of_week' => 1,
        'delivery_time' => '09:00',
        'position' => 1,
        'active' => true,
    ]);

    app(PromptScheduleManager::class)->remove($outsider, $relationship, $schedule);
})->throws(DomainException::class, 'You cannot manage this relationship schedule.');

test('a non administrator cannot change their relationship schedule', function () {
    [, $relationship, $member] = scheduleSettingsRelationship();
    $library = PromptLibrary::query()->create([
        'name' => 'Admin only schedule',
        'slug' => 'admin-only-schedule-library',
        'kind' => PromptRoundKind::SharedQuestion,
        'active' => true,
    ]);

    app(PromptScheduleManager::class)->add($member, $relationship, $library, 1, '09:00');
})->throws(DomainException::class, 'Only an administrator can manage the prompt schedule.');

/** @return array{User, Relationship, User} */
function scheduleSettingsRelationship(): array
{
    $user = User::factory()->admin()->create();
    $partner = User::factory()->create();
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$user->id, $partner->id], ['joined_at' => now()]);

    return [$user, $relationship, $partner];
}
