<?php

use App\Enums\PromptPhotoRequirement;
use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundOrigin;
use App\Enums\PromptRoundStatus;
use App\Models\PromptLibrary;
use App\Models\PromptTemplate;
use App\Models\Relationship;
use App\Models\User;
use App\Services\DailyPromptScheduler;
use App\Services\ExtracurricularPromptManager;
use App\Services\PromptRoundWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('curated question prompts can require answer photos from either or both people', function () {
    Storage::fake('local');
    config()->set('filesystems.media_disk', 'local');
    $first = User::factory()->create();
    $second = User::factory()->create();
    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$first->id, $second->id], ['joined_at' => now()]);
    $library = promptExtensionLibrary($relationship);
    $template = promptExtensionTemplate($relationship, $library, PromptPhotoRequirement::Both);
    $scheduler = app(DailyPromptScheduler::class);
    $tasks = $scheduler->tasksFor($relationship, $template, $scheduler->membersFor($relationship));

    expect($tasks[0]['payload']['requires_photos'])->toBeTrue()
        ->and($tasks[1]['payload']['requires_photos'])->toBeTrue();

    $round = app(PromptRoundWorkflow::class)->startRound($relationship, $template->kind, $tasks, template: $template, library: $library);

    $this->actingAs($first);
    Livewire::test('current-prompt', ['roundId' => $round->id])
        ->set('answer', 'A thoughtful answer.')
        ->call('submitAnswer')
        ->assertHasErrors('answerPhotos')
        ->set('answerPhotos', [UploadedFile::fake()->image('answer.jpg')])
        ->call('submitAnswer')
        ->assertHasNoErrors();

    expect($round->tasks()->where('user_id', $first->id)->firstOrFail()->photos()->count())->toBe(1);
});

test('extracurricular libraries issue one persistent on demand round per local day', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $relationship = Relationship::query()->create(['timezone' => 'America/Chicago']);
    $relationship->members()->attach([$first->id, $second->id], ['joined_at' => now()]);
    $library = promptExtensionLibrary($relationship);
    promptExtensionTemplate($relationship, $library);
    $relationship->extracurricularLibraries()->attach($library);
    $manager = app(ExtracurricularPromptManager::class);
    $workflow = app(PromptRoundWorkflow::class);
    $round = $manager->request($first, $relationship, $library);

    expect($round->origin)->toBe(PromptRoundOrigin::Extracurricular)
        ->and($round->requested_by_user_id)->toBe($first->id)
        ->and($manager->request($second, $relationship, $library)->is($round))->toBeTrue();

    foreach ($round->tasks as $task) {
        $workflow->submitQuestion($task, $task->assignee, 'Done.');
    }

    expect($round->fresh()->status)->toBe(PromptRoundStatus::Revealed);

    expect(fn () => $manager->request($first, $relationship, $library))
        ->toThrow(DomainException::class, 'already requested');
});

test('exposed libraries appear on the home screen and prompt pages are private to the couple', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $outsider = User::factory()->create();
    $relationship = Relationship::query()->create();
    $relationship->members()->attach([$first->id, $second->id], ['joined_at' => now()]);
    $library = promptExtensionLibrary($relationship);
    promptExtensionTemplate($relationship, $library);
    $relationship->extracurricularLibraries()->attach($library);
    $round = app(ExtracurricularPromptManager::class)->request($first, $relationship, $library);

    $this->actingAs($first)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee($library->name)
        ->assertSee(route('extracurriculars.show', $library), false);

    $this->get(route('prompts.show', $round))->assertOk()->assertSee('Your prompt');
    $this->actingAs($outsider)->get(route('prompts.show', $round))->assertForbidden();
});

function promptExtensionLibrary(Relationship $relationship): PromptLibrary
{
    return $relationship->promptLibraries()->create([
        'name' => 'Weekend connection',
        'slug' => 'weekend-connection-'.str()->random(8),
        'kind' => PromptRoundKind::SharedQuestion,
        'active' => true,
    ]);
}

function promptExtensionTemplate(
    Relationship $relationship,
    PromptLibrary $library,
    PromptPhotoRequirement $photoRequirement = PromptPhotoRequirement::None,
): PromptTemplate {
    return $library->prompts()->create([
        'relationship_id' => $relationship->id,
        'slug' => 'weekend-prompt-'.str()->uuid(),
        'kind' => PromptRoundKind::SharedQuestion,
        'primary_prompt' => 'What should we make time for this weekend?',
        'photo_requirement' => $photoRequirement,
        'active' => true,
        'position' => 1,
    ]);
}
