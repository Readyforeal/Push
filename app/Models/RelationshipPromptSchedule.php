<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $relationship_id
 * @property int|null $prompt_library_id
 * @property int|null $prompt_template_id
 * @property int $day_of_week
 * @property string $delivery_time
 * @property int $position
 * @property bool $active
 * @property-read Relationship $relationship
 * @property-read PromptTemplate $template
 * @property-read PromptLibrary|null $library
 */
class RelationshipPromptSchedule extends Model
{
    protected $fillable = [
        'relationship_id',
        'prompt_library_id',
        'prompt_template_id',
        'day_of_week',
        'delivery_time',
        'position',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'position' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Relationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }

    /** @return BelongsTo<PromptTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PromptTemplate::class, 'prompt_template_id');
    }

    /** @return BelongsTo<PromptLibrary, $this> */
    public function library(): BelongsTo
    {
        return $this->belongsTo(PromptLibrary::class, 'prompt_library_id');
    }

    /** @return HasMany<PromptRound, $this> */
    public function rounds(): HasMany
    {
        return $this->hasMany(PromptRound::class);
    }
}
