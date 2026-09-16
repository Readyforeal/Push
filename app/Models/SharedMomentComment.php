<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharedMomentComment extends Model
{
    protected $fillable = ['shared_moment_id', 'user_id', 'body'];

    /** @return BelongsTo<SharedMoment, $this> */
    public function moment(): BelongsTo
    {
        return $this->belongsTo(SharedMoment::class, 'shared_moment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
