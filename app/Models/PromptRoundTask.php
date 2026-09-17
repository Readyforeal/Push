<?php

namespace App\Models;

use App\Enums\PromptTaskKind;
use App\Enums\PromptTaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $prompt_round_id
 * @property int $user_id
 * @property int|null $depends_on_task_id
 * @property PromptTaskKind $kind
 * @property PromptTaskStatus $status
 * @property string|null $prompt
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $submitted_at
 * @property-read PromptRound $round
 * @property-read User $assignee
 * @property-read QuestionResponse|null $questionResponse
 */
class PromptRoundTask extends Model
{
    protected $fillable = [
        'prompt_round_id',
        'user_id',
        'depends_on_task_id',
        'kind',
        'status',
        'prompt',
        'payload',
        'position',
        'activated_at',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PromptTaskKind::class,
            'status' => PromptTaskStatus::class,
            'payload' => 'array',
            'activated_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PromptRound, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(PromptRound::class, 'prompt_round_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<PromptRoundTask, $this> */
    public function dependency(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_task_id');
    }

    /** @return HasMany<PromptRoundTask, $this> */
    public function dependents(): HasMany
    {
        return $this->hasMany(self::class, 'depends_on_task_id');
    }

    /** @return HasOne<QuestionResponse, $this> */
    public function questionResponse(): HasOne
    {
        return $this->hasOne(QuestionResponse::class);
    }

    /** @return HasMany<RoundPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(RoundPhoto::class)->orderBy('position');
    }

    /** @return HasOne<PhotoSelection, $this> */
    public function photoSelection(): HasOne
    {
        return $this->hasOne(PhotoSelection::class);
    }
}
