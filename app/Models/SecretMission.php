<?php

namespace App\Models;

use App\Enums\SecretMissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $relationship_id
 * @property int $assignee_user_id
 * @property int $beneficiary_user_id
 * @property SecretMissionStatus $status
 * @property-read User $assignee
 * @property-read User $beneficiary
 */
class SecretMission extends Model
{
    protected $fillable = [
        'relationship_id',
        'secret_mission_prompt_id',
        'assignee_user_id',
        'beneficiary_user_id',
        'body',
        'status',
        'accepted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SecretMissionStatus::class,
            'accepted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Relationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }

    /** @return BelongsTo<SecretMissionPrompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(SecretMissionPrompt::class, 'secret_mission_prompt_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }
}
