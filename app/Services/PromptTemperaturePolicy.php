<?php

namespace App\Services;

use App\Models\PromptTemplate;
use App\Models\Relationship;
use Illuminate\Database\Eloquent\Collection;

class PromptTemperaturePolicy
{
    public function __construct(
        private RelationshipTemperature $temperature,
    ) {}

    public function allows(Relationship $relationship, PromptTemplate $template): bool
    {
        return $this->allowsAt(
            $template,
            $this->temperature->current($relationship),
            $relationship->promptTagRules()->pluck('minimum_temperature', 'tag')->all(),
        );
    }

    /**
     * @param  Collection<int, PromptTemplate>  $templates
     * @return Collection<int, PromptTemplate>
     */
    public function filter(Relationship $relationship, Collection $templates): Collection
    {
        $temperature = $this->temperature->current($relationship);
        $minimums = $relationship->promptTagRules()
            ->pluck('minimum_temperature', 'tag')
            ->all();

        return $templates
            ->filter(fn (PromptTemplate $template): bool => $this->allowsAt($template, $temperature, $minimums))
            ->values();
    }

    /** @param array<string, int|string> $minimums */
    private function allowsAt(PromptTemplate $template, int $temperature, array $minimums): bool
    {
        return collect($template->topics ?? [])
            ->filter(fn (string $tag): bool => filled($tag))
            ->map(fn (string $tag): string => mb_strtolower(trim($tag)))
            ->unique()
            ->every(fn (string $tag): bool => $temperature >= (int) ($minimums[$tag] ?? 1));
    }
}
