<?php

namespace Database\Seeders;

use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskKind;
use App\Models\Relationship;
use App\Models\User;
use App\Services\PromptRoundWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoUniqueQuestionRoundSeeder extends Seeder
{
    public const FIRST_PROMPT = 'What is one quality in your partner you have appreciated more lately?';

    public const SECOND_PROMPT = 'What is something you wish your partner understood about how you receive support?';

    public function run(): void
    {
        $workflow = app(PromptRoundWorkflow::class);

        Relationship::query()->each(function (Relationship $relationship) use ($workflow): void {
            $tasks = $this->tasksFor($relationship);

            if (count($tasks) !== 2) {
                return;
            }

            $alreadySeeded = $relationship->rounds()
                ->where('kind', PromptRoundKind::UniqueQuestions)
                ->whereHas('tasks', fn ($query) => $query->where('prompt', self::FIRST_PROMPT))
                ->exists();
            $hasActiveRound = $relationship->rounds()
                ->where('status', PromptRoundStatus::Active)
                ->exists();

            if ($alreadySeeded || $hasActiveRound) {
                return;
            }

            $workflow->startRound($relationship, PromptRoundKind::UniqueQuestions, $tasks);
        });
    }

    /**
     * @return list<array{user: User, kind: PromptTaskKind, prompt: string}>
     */
    private function tasksFor(Relationship $relationship): array
    {
        $memberIds = DB::table('relationship_members')
            ->where('relationship_id', $relationship->id)
            ->orderBy('id')
            ->pluck('user_id');
        $prompts = [self::FIRST_PROMPT, self::SECOND_PROMPT];
        $tasks = [];

        foreach ($memberIds as $position => $memberId) {
            if (! isset($prompts[$position])) {
                return [];
            }

            $tasks[] = [
                'user' => User::query()->findOrFail((int) $memberId),
                'kind' => PromptTaskKind::Question,
                'prompt' => $prompts[$position],
            ];
        }

        return $tasks;
    }
}
