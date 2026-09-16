<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecretMissionPrompt extends Model
{
    protected $fillable = ['relationship_id', 'beneficiary_user_id', 'body', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return BelongsTo<Relationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }

    /** @return BelongsTo<User, $this> */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    /** @return HasMany<SecretMission, $this> */
    public function missions(): HasMany
    {
        return $this->hasMany(SecretMission::class);
    }
}
