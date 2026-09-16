<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharedMomentPhoto extends Model
{
    protected $fillable = [
        'shared_moment_id',
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

    /** @return BelongsTo<SharedMoment, $this> */
    public function moment(): BelongsTo
    {
        return $this->belongsTo(SharedMoment::class, 'shared_moment_id');
    }
}
