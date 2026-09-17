<?php

namespace App\Services;

use App\Enums\PromptPhotoRequirement;
use App\Enums\PromptRoundKind;
use App\Models\PromptLibrary;
use App\Models\PromptTemplate;
use App\Models\Relationship;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromptLibraryManager
{
    public function createLibrary(
        User $actor,
        Relationship $relationship,
        string $name,
        PromptRoundKind $kind,
        ?string $description = null,
    ): PromptLibrary {
        $this->authorizeRelationship($actor, $relationship);

        return $relationship->promptLibraries()->create([
            'name' => trim($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'description' => filled($description) ? trim((string) $description) : null,
            'kind' => $kind,
            'active' => true,
        ]);
    }

    /**
     * @param  list<string>  $topics
     */
    public function addPrompt(
        User $actor,
        Relationship $relationship,
        PromptLibrary $library,
        string $primaryPrompt,
        ?string $secondaryPrompt = null,
        array $topics = [],
        ?int $primaryUserId = null,
        PromptPhotoRequirement $photoRequirement = PromptPhotoRequirement::None,
    ): PromptTemplate {
        $this->authorizeLibrary($actor, $relationship, $library);
        $this->validatePromptPair($library, $primaryPrompt, $secondaryPrompt);
        $primaryUserId = $this->primaryUserIdFor($relationship, $library->kind, $primaryUserId);
        $position = (int) $library->prompts()->max('position') + 1;

        return $library->prompts()->create([
            'relationship_id' => $relationship->id,
            'primary_user_id' => $primaryUserId,
            'photo_requirement' => $photoRequirement,
            'slug' => 'custom-'.Str::uuid(),
            'kind' => $library->kind,
            'primary_prompt' => trim($primaryPrompt),
            'secondary_prompt' => filled($secondaryPrompt) ? trim((string) $secondaryPrompt) : null,
            'topics' => $this->normalizeTopics($topics),
            'active' => true,
            'position' => $position,
        ]);
    }

    public function importPrompts(
        User $actor,
        Relationship $relationship,
        PromptLibrary $library,
        string $contents,
        ?int $primaryUserId = null,
        PromptPhotoRequirement $photoRequirement = PromptPhotoRequirement::None,
    ): int {
        $this->authorizeLibrary($actor, $relationship, $library);
        $primaryUserId = $this->primaryUserIdFor($relationship, $library->kind, $primaryUserId);
        $rows = preg_split('/\R/u', $contents) ?: [];
        $parsed = [];

        foreach ($rows as $lineNumber => $row) {
            if (blank($row)) {
                continue;
            }

            $columns = str_contains($row, "\t")
                ? str_getcsv($row, "\t")
                : array_map('trim', explode('|||', $row));
            $primary = trim((string) ($columns[0] ?? ''));
            $secondary = filled($columns[1] ?? null) ? trim((string) $columns[1]) : null;
            $topics = filled($columns[2] ?? null)
                ? array_map('trim', explode(',', (string) $columns[2]))
                : [];

            try {
                $this->validatePromptPair($library, $primary, $secondary);
            } catch (DomainException $exception) {
                throw new DomainException('Line '.($lineNumber + 1).': '.$exception->getMessage());
            }

            $parsed[] = [$primary, $secondary, $topics];
        }

        if ($parsed === []) {
            throw new DomainException('Add at least one prompt to import.');
        }

        DB::transaction(function () use ($relationship, $library, $parsed, $primaryUserId, $photoRequirement): void {
            $position = (int) $library->prompts()->max('position');
            $now = now();
            $inserts = [];

            foreach ($parsed as [$primary, $secondary, $topics]) {
                $inserts[] = [
                    'prompt_library_id' => $library->id,
                    'relationship_id' => $relationship->id,
                    'primary_user_id' => $primaryUserId,
                    'photo_requirement' => $photoRequirement->value,
                    'slug' => 'custom-'.Str::uuid(),
                    'kind' => $library->kind->value,
                    'primary_prompt' => trim($primary),
                    'secondary_prompt' => filled($secondary) ? trim((string) $secondary) : null,
                    'topics' => json_encode($this->normalizeTopics($topics), JSON_THROW_ON_ERROR),
                    'active' => true,
                    'position' => ++$position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($inserts, 75) as $chunk) {
                PromptTemplate::query()->insert($chunk);
            }
        });

        return count($parsed);
    }

    public function swapPromptAssignment(
        User $actor,
        Relationship $relationship,
        PromptTemplate $prompt,
    ): PromptTemplate {
        $this->authorizeRelationship($actor, $relationship);

        if ($prompt->relationship_id !== $relationship->id) {
            throw new DomainException('Built-in prompts cannot be changed.');
        }

        if (! $this->usesNamedAssignments($prompt->kind)) {
            throw new DomainException('This prompt is shared equally by both partners.');
        }

        $memberIds = $this->orderedMemberIds($relationship);

        if (count($memberIds) !== 2) {
            throw new DomainException('Prompt roles require exactly two partners.');
        }

        $currentUserId = in_array($prompt->primary_user_id, $memberIds, true)
            ? $prompt->primary_user_id
            : $memberIds[0];
        $prompt->update([
            'primary_user_id' => $memberIds[0] === $currentUserId ? $memberIds[1] : $memberIds[0],
        ]);

        return $prompt->refresh();
    }

    public function removePrompt(
        User $actor,
        Relationship $relationship,
        PromptTemplate $prompt,
    ): void {
        $this->authorizeRelationship($actor, $relationship);

        if ($prompt->relationship_id !== $relationship->id) {
            throw new DomainException('Built-in prompts cannot be removed.');
        }

        $prompt->delete();
    }

    public function removeLibrary(
        User $actor,
        Relationship $relationship,
        PromptLibrary $library,
    ): void {
        $this->authorizeRelationship($actor, $relationship);

        if ($library->relationship_id !== $relationship->id) {
            throw new DomainException('Built-in libraries cannot be removed.');
        }

        $library->delete();
    }

    public function setExtracurricularExposure(
        User $actor,
        Relationship $relationship,
        PromptLibrary $library,
        bool $exposed,
    ): void {
        $this->authorizeLibrary($actor, $relationship, $library);

        if ($exposed) {
            $relationship->extracurricularLibraries()->syncWithoutDetaching([$library->id]);

            return;
        }

        $relationship->extracurricularLibraries()->detach($library->id);
    }

    private function authorizeLibrary(
        User $actor,
        Relationship $relationship,
        PromptLibrary $library,
    ): void {
        $this->authorizeRelationship($actor, $relationship);

        if (! $library->active
            || ($library->relationship_id !== null && $library->relationship_id !== $relationship->id)) {
            throw new DomainException('That prompt library is not available to your relationship.');
        }
    }

    private function authorizeRelationship(User $actor, Relationship $relationship): void
    {
        if (! $relationship->hasMember($actor)) {
            throw new DomainException('You cannot manage this relationship’s prompt libraries.');
        }
    }

    private function validatePromptPair(
        PromptLibrary $library,
        string $primaryPrompt,
        ?string $secondaryPrompt,
    ): void {
        if (blank($primaryPrompt)) {
            throw new DomainException('The primary prompt is required.');
        }

        if ($library->kind !== PromptRoundKind::SharedQuestion && blank($secondaryPrompt)) {
            throw new DomainException('This library type requires both prompt fields.');
        }
    }

    private function primaryUserIdFor(
        Relationship $relationship,
        PromptRoundKind $kind,
        ?int $primaryUserId,
    ): ?int {
        if (! $this->usesNamedAssignments($kind)) {
            return null;
        }

        $memberIds = $this->orderedMemberIds($relationship);

        if (count($memberIds) !== 2) {
            throw new DomainException('Prompt roles require exactly two partners.');
        }

        $primaryUserId ??= $memberIds[0];

        if (! in_array($primaryUserId, $memberIds, true)) {
            throw new DomainException('The selected prompt recipient is not part of this relationship.');
        }

        return $primaryUserId;
    }

    private function usesNamedAssignments(PromptRoundKind $kind): bool
    {
        return in_array($kind, [PromptRoundKind::UniqueQuestions, PromptRoundKind::PhotoRequest], true);
    }

    /** @return list<int> */
    private function orderedMemberIds(Relationship $relationship): array
    {
        return $relationship->members()
            ->orderBy('relationship_members.id')
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<string>  $topics
     * @return list<string>
     */
    private function normalizeTopics(array $topics): array
    {
        $normalized = [];

        foreach ($topics as $topic) {
            $topic = Str::lower(trim($topic));

            if ($topic !== '' && ! in_array($topic, $normalized, true)) {
                $normalized[] = $topic;
            }
        }

        return $normalized;
    }
}
