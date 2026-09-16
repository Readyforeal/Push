<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $prompt_round_task_id
 * @property int $round_photo_id
 * @property-read PromptRoundTask $task
 * @property-read RoundPhoto $photo
 */
class PhotoSelection extends Model
{
    protected $fillable = ['prompt_round_task_id', 'round_photo_id'];

    /** @return BelongsTo<PromptRoundTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(PromptRoundTask::class, 'prompt_round_task_id');
    }

    /** @return BelongsTo<RoundPhoto, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(RoundPhoto::class, 'round_photo_id');
    }
}
