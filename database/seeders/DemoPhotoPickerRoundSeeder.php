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

class DemoPhotoPickerRoundSeeder extends Seeder
{
    public const UPLOAD_PROMPT = 'Choose three photos that capture a memory, place, or little moment you love.';

    public const PICK_PROMPT = 'Which of these photos is your favorite?';

    public function run(): void
    {
        $workflow = app(PromptRoundWorkflow::class);

        Relationship::query()->each(function (Relationship $relationship) use ($workflow): void {
            $members = $this->membersFor($relationship);

            if (count($members) !== 2) {
                return;
            }

            $alreadySeeded = $relationship->rounds()
                ->where('kind', PromptRoundKind::PhotoPicker)
                ->whereHas('tasks', fn ($query) => $query->where('prompt', self::UPLOAD_PROMPT))
                ->exists();
            $hasActiveRound = $relationship->rounds()
                ->where('status', PromptRoundStatus::Active)
                ->exists();

            if ($alreadySeeded || $hasActiveRound) {
                return;
            }

            $workflow->startRound($relationship, PromptRoundKind::PhotoPicker, [
                [
                    'user' => $members[0],
                    'kind' => PromptTaskKind::PhotoUpload,
                    'prompt' => self::UPLOAD_PROMPT,
                ],
                [
                    'user' => $members[1],
                    'kind' => PromptTaskKind::PhotoUpload,
                    'prompt' => self::UPLOAD_PROMPT,
                ],
                [
                    'user' => $members[1],
                    'kind' => PromptTaskKind::PhotoPick,
                    'prompt' => self::PICK_PROMPT,
                    'depends_on' => 0,
                ],
                [
                    'user' => $members[0],
                    'kind' => PromptTaskKind::PhotoPick,
                    'prompt' => self::PICK_PROMPT,
                    'depends_on' => 1,
                ],
            ]);
        });
    }

    /** @return list<User> */
    private function membersFor(Relationship $relationship): array
    {
        $memberIds = DB::table('relationship_members')
            ->where('relationship_id', $relationship->id)
            ->orderBy('id')
            ->pluck('user_id');
        $members = [];

        foreach ($memberIds as $memberId) {
            $members[] = User::query()->findOrFail((int) $memberId);
        }

        return $members;
    }
}
