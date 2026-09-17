<?php

namespace App\Models;

use App\Enums\PromptRoundKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $relationship_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property PromptRoundKind $kind
 * @property bool $active
 */
class PromptLibrary extends Model
{
    protected $fillable = [
        'relationship_id',
        'name',
        'slug',
        'description',
        'kind',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PromptRoundKind::class,
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Relationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }

    /** @return HasMany<PromptTemplate, $this> */
    public function prompts(): HasMany
    {
        return $this->hasMany(PromptTemplate::class);
    }

    /** @return HasMany<RelationshipPromptSchedule, $this> */
    public function schedules(): HasMany
    {
        return $this->hasMany(RelationshipPromptSchedule::class);
    }

    /** @return BelongsToMany<Relationship, $this> */
    public function extracurricularRelationships(): BelongsToMany
    {
        return $this->belongsToMany(Relationship::class, 'relationship_extracurricular_libraries')
            ->withTimestamps();
    }
}
