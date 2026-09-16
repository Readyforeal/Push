<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $prompt_round_task_id
 * @property string $disk
 * @property string $path
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $size
 * @property int $position
 * @property-read PromptRoundTask $task
 */
class RoundPhoto extends Model
{
    protected $fillable = [
        'prompt_round_task_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'position',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer', 'position' => 'integer'];
    }

    /** @return BelongsTo<PromptRoundTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(PromptRoundTask::class, 'prompt_round_task_id');
    }
}
