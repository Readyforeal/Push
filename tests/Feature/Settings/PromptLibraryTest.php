<?php

use App\Enums\PromptRoundKind;
use App\Models\PromptLibrary;
use App\Models\PromptTemplate;
use App\Models\Relationship;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PromptTemplateSeeder;
use Livewire\Livewire;

test('the default libraries are seeded without built in prompts', function () {
    PromptTemplate::query()->create([
        'slug' => 'legacy-built-in-prompt',
        'kind' => PromptRoundKind::SharedQuestion,
        'primary_prompt' => 'A legacy built-in question?',
        'active' => true,
    ]);

    $this->seed(PromptTemplateSeeder::class);

    expect(PromptLibrary::query()->whereNull('relationship_id')->count())->toBe(4)
        ->and(PromptTemplate::query()->whereNull('relationship_id')->count())->toBe(0);
});

test('the default database seed does not create demo prompt history', function () {
    [, $relationship] = promptLibraryRelationship();

    $this->seed(DatabaseSeeder::class);

    expect($relationship->rounds()->count())->toBe(0);
});

test('partners can create a library and add prompts individually or in bulk', function () {
    [$user, $relationship, $partner] = promptLibraryRelationship();

    $this->actingAs($user);
    $component = Livewire::test('pages::settings.prompt-libraries')
        ->set('libraryName', 'Deeper conversations')
        ->set('libraryDescription', 'Questions about trust and closeness.')
        ->set('libraryKind', PromptRoundKind::UniqueQuestions->value)
        ->call('createLibrary')
        ->assertHasNoErrors()
        ->assertSee('Deeper conversations');

    $library = $relationship->promptLibraries()->sole();

    $component
        ->set('primaryAssigneeId', $partner->id)
        ->set('primaryPrompt', 'When do you feel closest to me?')
        ->set('secondaryPrompt', 'What helps you feel safe opening up?')
        ->set('topics', 'Trust, Intimacy, trust')
        ->call('addPrompt')
        ->assertHasNoErrors()
        ->set('bulkPrompts', implode("\n", [
            'What do you want us to protect? ||| What makes our relationship feel steady? ||| values, commitment',
            "What would you like more of?\tWhat would make this week feel connected?\tconnection, weekly check-in",
        ]))
        ->call('importPrompts')
        ->assertHasNoErrors()
        ->assertSee('When do you feel closest to me?')
        ->assertSee('trust');

    expect($library->prompts()->count())->toBe(3)
        ->and($library->prompts()->oldest('id')->firstOrFail()->topics)->toBe(['trust', 'intimacy'])
        ->and($library->prompts()->pluck('primary_user_id')->unique()->all())->toBe([$partner->id]);
});

test('named prompt assignments can be swapped between partners', function () {
    [$user, $relationship, $partner] = promptLibraryRelationship();
    $library = $relationship->promptLibraries()->create([
        'name' => 'Questions just for you',
        'slug' => 'named-assignment-test',
        'kind' => PromptRoundKind::UniqueQuestions,
        'active' => true,
    ]);

    $this->actingAs($user);
    $component = Livewire::test('pages::settings.prompt-libraries')
        ->set('selectedLibraryId', $library->id)
        ->assertSee($user->firstName())
        ->assertSee($partner->firstName())
        ->set('primaryAssigneeId', $partner->id)
        ->set('primaryPrompt', 'A question selected for Taylor?')
        ->set('secondaryPrompt', 'A question selected for Jamie?')
        ->call('addPrompt')
        ->assertHasNoErrors();

    $prompt = $library->prompts()->sole();

    expect($prompt->primary_user_id)->toBe($partner->id);

    $component->call('swapPromptAssignment', $prompt->id)->assertHasNoErrors();

    expect($prompt->refresh()->primary_user_id)->toBe($user->id);
});

test('bulk import reports the failing line and does not partially import', function () {
    [$user, $relationship] = promptLibraryRelationship();
    $library = $relationship->promptLibraries()->create([
        'name' => 'Paired questions',
        'slug' => 'paired-questions-test',
        'kind' => PromptRoundKind::UniqueQuestions,
        'active' => true,
    ]);

    $this->actingAs($user);
    Livewire::test('pages::settings.prompt-libraries')
        ->set('selectedLibraryId', $library->id)
        ->set('bulkPrompts', "Valid first ||| Valid second\nMissing its pair")
        ->call('importPrompts')
        ->assertHasErrors('bulkPrompts');

    expect($library->prompts()->count())->toBe(0);
});

test('large bulk imports are inserted in safe chunks', function () {
    [$user, $relationship] = promptLibraryRelationship();
    $library = $relationship->promptLibraries()->create([
        'name' => 'Big question bank',
        'slug' => 'big-question-bank-test',
        'kind' => PromptRoundKind::SharedQuestion,
        'active' => true,
    ]);
    $rows = collect(range(1, 160))
        ->map(fn (int $number) => "Question {$number}? ||| ||| topic {$number}")
        ->implode("\n");

    $this->actingAs($user);
    Livewire::test('pages::settings.prompt-libraries')
        ->set('selectedLibraryId', $library->id)
        ->set('bulkPrompts', $rows)
        ->call('importPrompts')
        ->assertHasNoErrors();

    expect($library->prompts()->count())->toBe(160)
        ->and($library->prompts()->max('position'))->toBe(160);
});

test('relationship-owned libraries stay private to that couple', function () {
    [$owner, $relationship] = promptLibraryRelationship();
    [$outsider] = promptLibraryRelationship();
    $relationship->promptLibraries()->create([
        'name' => 'Our private topics',
        'slug' => 'our-private-topics-test',
        'kind' => PromptRoundKind::SharedQuestion,
        'active' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('prompt-libraries.edit'))
        ->assertOk()
        ->assertSee('Our private topics');

    $this->actingAs($outsider)
        ->get(route('prompt-libraries.edit'))
        ->assertOk()
        ->assertDontSee('Our private topics');
});

/** @return array{User, Relationship, User} */
function promptLibraryRelationship(): array
{
    $user = User::factory()->create(['name' => 'Jamie Rivera']);
    $partner = User::factory()->create(['name' => 'Taylor Morgan']);
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$user->id, $partner->id], ['joined_at' => now()]);

    return [$user, $relationship, $partner];
}
