<?php

use App\Enums\PromptRoundKind;
use App\Models\PromptLibrary;
use App\Models\PromptTemplate;
use App\Models\Relationship;
use App\Models\RelationshipPromptTagRule;
use App\Models\User;
use Livewire\Livewire;

test('an administrator can set the minimum relationship temperature for prompt tags', function () {
    $admin = User::factory()->admin()->create();
    $partner = User::factory()->create();
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$admin->id, $partner->id], ['joined_at' => now()]);
    $library = PromptLibrary::query()->create([
        'relationship_id' => $relationship->id,
        'name' => 'Connection',
        'slug' => 'temperature-rules-connection',
        'kind' => PromptRoundKind::SharedQuestion,
        'active' => true,
    ]);
    PromptTemplate::query()->create([
        'prompt_library_id' => $library->id,
        'relationship_id' => $relationship->id,
        'slug' => 'tagged-temperature-prompt',
        'kind' => PromptRoundKind::SharedQuestion,
        'primary_prompt' => 'What feels close today?',
        'topics' => ['intimacy', 'playful'],
        'active' => true,
        'position' => 1,
    ]);
    $relationship->temperatureCheckIns()->create(['user_id' => $admin->id, 'value' => 9]);
    $relationship->temperatureCheckIns()->create(['user_id' => $partner->id, 'value' => 4]);

    $this->actingAs($admin);

    Livewire::test('pages::settings.prompt-temperature')
        ->assertSee('Current shared temperature')
        ->assertSee('Tender')
        ->assertSee('intimacy')
        ->set('rules.0.minimum_temperature', 8)
        ->call('save')
        ->assertHasNoErrors();

    expect(RelationshipPromptTagRule::query()->sole())
        ->tag->toBe('intimacy')
        ->minimum_temperature->toBe(8);
});

test('prompt temperature settings are admin only', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('prompt-temperature.edit'))
        ->assertForbidden();
});
