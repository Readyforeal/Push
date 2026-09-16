<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SharedMoment extends Model
{
    protected $fillable = ['relationship_id', 'user_id', 'intensity', 'body'];

    protected function casts(): array
    {
        return ['intensity' => 'integer'];
    }

    /** @return BelongsTo<Relationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<SharedMomentPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(SharedMomentPhoto::class)->orderBy('position');
    }

    /** @return HasMany<SharedMomentComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(SharedMomentComment::class)->oldest();
    }
}
