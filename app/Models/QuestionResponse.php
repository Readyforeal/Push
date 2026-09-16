<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $prompt_round_task_id
 * @property string|null $answer
 * @property-read PromptRoundTask $task
 */
class QuestionResponse extends Model
{
    protected $fillable = ['prompt_round_task_id', 'answer'];

    /** @return BelongsTo<PromptRoundTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(PromptRoundTask::class, 'prompt_round_task_id');
    }
}
