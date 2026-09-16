<?php

namespace App\Models;

use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $relationship_id
 * @property int|null $prompt_template_id
 * @property int|null $relationship_prompt_schedule_id
 * @property PromptRoundKind $kind
 * @property PromptRoundStatus $status
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $scheduled_for
 * @property CarbonImmutable|null $revealed_at
 * @property-read Relationship $relationship
 */
class PromptRound extends Model
{
    protected $fillable = ['relationship_id', 'prompt_library_id', 'prompt_template_id', 'relationship_prompt_schedule_id', 'kind', 'prompt_source', 'ai_model', 'status', 'available_at', 'scheduled_for', 'revealed_at'];

    protected function casts(): array
    {
        return [
            'kind' => PromptRoundKind::class,
            'status' => PromptRoundStatus::class,
            'available_at' => 'immutable_datetime',
            'scheduled_for' => 'immutable_date',
            'revealed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Relationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }

    /** @return BelongsTo<PromptLibrary, $this> */
    public function library(): BelongsTo
    {
        return $this->belongsTo(PromptLibrary::class, 'prompt_library_id');
    }

    /** @return BelongsTo<PromptTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PromptTemplate::class, 'prompt_template_id');
    }

    /** @return BelongsTo<RelationshipPromptSchedule, $this> */
    public function promptSchedule(): BelongsTo
    {
        return $this->belongsTo(RelationshipPromptSchedule::class);
    }

    /** @return HasMany<PromptRoundTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(PromptRoundTask::class)->orderBy('position');
    }

    public function isRevealed(): bool
    {
        return $this->status === PromptRoundStatus::Revealed;
    }
}
