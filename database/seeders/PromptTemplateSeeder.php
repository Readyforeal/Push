<?php

namespace Database\Seeders;

use App\Enums\PromptRoundKind;
use App\Models\PromptLibrary;
use App\Models\PromptTemplate;
use Illuminate\Database\Seeder;

class PromptTemplateSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            [
                'slug' => 'built-in-shared-question',
                'name' => 'Shared connection',
                'description' => 'Questions you both answer and reveal together.',
                'kind' => PromptRoundKind::SharedQuestion,
            ],
            [
                'slug' => 'built-in-unique-questions',
                'name' => 'Individual reflection',
                'description' => 'Different questions for each partner, revealed together.',
                'kind' => PromptRoundKind::UniqueQuestions,
            ],
            [
                'slug' => 'built-in-photo-picker',
                'name' => 'Photo memories',
                'description' => 'You each share three photos and choose a favorite from each other.',
                'kind' => PromptRoundKind::PhotoPicker,
            ],
            [
                'slug' => 'built-in-photo-request',
                'name' => 'Photo requests',
                'description' => 'Ask your partner for something you would love to see, then pick your favorite.',
                'kind' => PromptRoundKind::PhotoRequest,
            ],
        ])->each(function (array $library): void {
            PromptLibrary::query()->updateOrCreate(
                ['slug' => $library['slug']],
                [
                    ...$library,
                    'relationship_id' => null,
                    'active' => true,
                ],
            );
        });

        PromptTemplate::query()->whereNull('relationship_id')->delete();
    }
}
