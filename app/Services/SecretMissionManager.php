<?php

namespace App\Services;

use App\Enums\SecretMissionStatus;
use App\Models\Relationship;
use App\Models\SecretMission;
use App\Models\SecretMissionPrompt;
use App\Models\User;
use App\Notifications\SecretMissionClaimedNotification;
use App\Notifications\SecretMissionCompletedNotification;
use DomainException;
use Illuminate\Support\Facades\DB;

class SecretMissionManager
{
    public function addPrompt(User $actor, Relationship $relationship, string $body): SecretMissionPrompt
    {
        $this->authorizeMember($actor, $relationship);

        if (blank(trim($body))) {
            throw new DomainException('Write a mission first.');
        }

        return $relationship->secretMissionPrompts()->create([
            'beneficiary_user_id' => $actor->id,
            'body' => trim($body),
            'active' => true,
        ]);
    }

    public function importPrompts(User $actor, Relationship $relationship, string $contents): int
    {
        $this->authorizeMember($actor, $relationship);
        $prompts = collect(preg_split('/\R/u', $contents) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values();

        if ($prompts->isEmpty()) {
            throw new DomainException('Add at least one mission to import.');
        }

        $now = now();
        $rows = $prompts->map(fn (string $body): array => [
            'relationship_id' => $relationship->id,
            'beneficiary_user_id' => $actor->id,
            'body' => $body,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 250) as $chunk) {
                SecretMissionPrompt::query()->insert($chunk);
            }
        });

        return count($rows);
    }

    public function removePrompt(User $actor, Relationship $relationship, SecretMissionPrompt $prompt): void
    {
        $this->authorizeMember($actor, $relationship);

        if ($prompt->relationship_id !== $relationship->id || $prompt->beneficiary_user_id !== $actor->id) {
            throw new DomainException('You can only remove missions from your own private list.');
        }

        $prompt->delete();
    }

    public function claim(User $actor, Relationship $relationship): SecretMission
    {
        $this->authorizeMember($actor, $relationship);

        $mission = DB::transaction(function () use ($actor, $relationship): SecretMission {
            $relationship = Relationship::query()->lockForUpdate()->findOrFail($relationship->id);

            if ($relationship->secretMissions()
                ->where('assignee_user_id', $actor->id)
                ->where('status', SecretMissionStatus::Active)
                ->exists()) {
                throw new DomainException('Finish your current secret mission before taking another.');
            }

            $partner = $relationship->members()
                ->whereKeyNot($actor->id)
                ->first();

            if (! $partner) {
                throw new DomainException('Pair with your partner before taking a secret mission.');
            }

            $prompt = $relationship->secretMissionPrompts()
                ->where('beneficiary_user_id', $partner->id)
                ->where('active', true)
                ->inRandomOrder()
                ->first();

            if (! $prompt) {
                throw new DomainException('Your partner has not added any secret missions yet.');
            }

            return $relationship->secretMissions()->create([
                'secret_mission_prompt_id' => $prompt->id,
                'assignee_user_id' => $actor->id,
                'beneficiary_user_id' => $partner->id,
                'body' => $prompt->body,
                'status' => SecretMissionStatus::Active,
                'accepted_at' => now(),
            ]);
        });

        $mission->beneficiary->notify(new SecretMissionClaimedNotification($actor->firstName()));

        return $mission;
    }

    public function complete(User $actor, Relationship $relationship, SecretMission $mission): SecretMission
    {
        $this->authorizeMember($actor, $relationship);

        $mission = DB::transaction(function () use ($actor, $relationship, $mission): SecretMission {
            $mission = SecretMission::query()->lockForUpdate()->findOrFail($mission->id);

            if ($mission->relationship_id !== $relationship->id || $mission->assignee_user_id !== $actor->id) {
                throw new DomainException('That secret mission is not assigned to you.');
            }

            if ($mission->status !== SecretMissionStatus::Active) {
                throw new DomainException('That secret mission is already complete.');
            }

            $mission->update([
                'status' => SecretMissionStatus::Completed,
                'completed_at' => now(),
            ]);

            return $mission->fresh(['beneficiary']);
        });

        $mission->beneficiary->notify(new SecretMissionCompletedNotification($actor->firstName()));

        return $mission;
    }

    private function authorizeMember(User $actor, Relationship $relationship): void
    {
        if (! $relationship->hasMember($actor)) {
            throw new DomainException('You cannot manage secret missions for this relationship.');
        }
    }
}
