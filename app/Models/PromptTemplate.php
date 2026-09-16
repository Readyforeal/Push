<?php

namespace App\Models;

use App\Enums\PromptRoundKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $prompt_library_id
 * @property int|null $relationship_id
 * @property string $slug
 * @property PromptRoundKind $kind
 * @property string $primary_prompt
 * @property string|null $secondary_prompt
 * @property list<string>|null $topics
 * @property bool $active
 * @property int $position
 */
class PromptTemplate extends Model
{
    protected $fillable = [
        'slug',
        'prompt_library_id',
        'relationship_id',
        'kind',
        'primary_prompt',
        'secondary_prompt',
        'topics',
        'active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PromptRoundKind::class,
            'topics' => 'array',
            'active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<PromptLibrary, $this> */
    public function library(): BelongsTo
    {
        return $this->belongsTo(PromptLibrary::class, 'prompt_library_id');
    }

    /** @return BelongsTo<Relationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }

    /** @return HasMany<PromptRound, $this> */
    public function rounds(): HasMany
    {
        return $this->hasMany(PromptRound::class);
    }
}
