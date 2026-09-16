<?php

namespace App\Services;

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
    ): PromptTemplate {
        $this->authorizeLibrary($actor, $relationship, $library);
        $this->validatePromptPair($library, $primaryPrompt, $secondaryPrompt);
        $position = (int) $library->prompts()->max('position') + 1;

        return $library->prompts()->create([
            'relationship_id' => $relationship->id,
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
    ): int {
        $this->authorizeLibrary($actor, $relationship, $library);
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

        DB::transaction(function () use ($relationship, $library, $parsed): void {
            $position = (int) $library->prompts()->max('position');
            $now = now();
            $inserts = [];

            foreach ($parsed as [$primary, $secondary, $topics]) {
                $inserts[] = [
                    'prompt_library_id' => $library->id,
                    'relationship_id' => $relationship->id,
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
