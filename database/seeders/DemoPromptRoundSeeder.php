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

class DemoPromptRoundSeeder extends Seeder
{
    public const PROMPT = 'What is one small thing your partner did recently that made you feel loved?';

    public function run(): void
    {
        $workflow = app(PromptRoundWorkflow::class);

        Relationship::query()->with('members')->each(function (Relationship $relationship) use ($workflow): void {
            $tasks = $this->tasksFor($relationship);

            if (count($tasks) !== 2) {
                return;
            }

            $alreadySeeded = $relationship->rounds()
                ->whereHas('tasks', fn ($query) => $query->where('prompt', self::PROMPT))
                ->exists();
            $hasActiveRound = $relationship->rounds()
                ->where('status', PromptRoundStatus::Active)
                ->exists();

            if ($alreadySeeded || $hasActiveRound) {
                return;
            }

            $workflow->startRound($relationship, PromptRoundKind::SharedQuestion, $tasks);
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

        $tasks = [];

        foreach ($memberIds as $memberId) {
            $tasks[] = [
                'user' => User::query()->findOrFail((int) $memberId),
                'kind' => PromptTaskKind::Question,
                'prompt' => self::PROMPT,
            ];
        }

        return $tasks;
    }
}
