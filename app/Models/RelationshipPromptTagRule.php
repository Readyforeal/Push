<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $relationship_id
 * @property string $tag
 * @property int $minimum_temperature
 */
#[Fillable(['relationship_id', 'tag', 'minimum_temperature'])]
class RelationshipPromptTagRule extends Model
{
    protected function casts(): array
    {
        return [
            'minimum_temperature' => 'integer',
        ];
    }

    /** @return BelongsTo<Relationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }
}
