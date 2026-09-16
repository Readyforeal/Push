<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $relationship_id
 * @property int $invited_by
 * @property string $email
 * @property string $token_hash
 * @property string|null $token
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $canceled_at
 * @property-read Relationship $relationship
 * @property-read User $inviter
 */
class RelationshipInvitation extends Model
{
    protected $fillable = [
        'relationship_id',
        'invited_by',
        'email',
        'token_hash',
        'token',
        'expires_at',
        'accepted_at',
        'canceled_at',
    ];

    protected $hidden = ['token_hash', 'token'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'token' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Relationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->canceled_at === null
            && $this->expires_at->isFuture();
    }
}
