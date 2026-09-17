<?php

namespace App\Models;

use App\Events\TemperatureCheckInCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $relationship_id
 * @property int $user_id
 * @property int $value
 * @property-read Relationship $relationship
 * @property-read User $user
 */
class TemperatureCheckIn extends Model
{
    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'created' => TemperatureCheckInCreated::class,
    ];

    protected $fillable = ['relationship_id', 'user_id', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }

    /** @return BelongsTo<Relationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
