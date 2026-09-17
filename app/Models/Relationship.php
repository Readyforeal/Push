<?php

namespace App\Models;

use App\Enums\PromptRoundStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string|null $name
 * @property string $timezone
 * @property-read Collection<int, User> $members
 */
class Relationship extends Model
{
    protected $fillable = ['name', 'timezone'];

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'relationship_members')
            ->withPivot(['joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<PromptRound, $this> */
    public function rounds(): HasMany
    {
        return $this->hasMany(PromptRound::class);
    }

    /** @return HasMany<RelationshipPromptSchedule, $this> */
    public function promptSchedules(): HasMany
    {
        return $this->hasMany(RelationshipPromptSchedule::class);
    }

    /** @return HasMany<PromptLibrary, $this> */
    public function promptLibraries(): HasMany
    {
        return $this->hasMany(PromptLibrary::class);
    }

    /** @return BelongsToMany<PromptLibrary, $this> */
    public function extracurricularLibraries(): BelongsToMany
    {
        return $this->belongsToMany(PromptLibrary::class, 'relationship_extracurricular_libraries')
            ->withTimestamps();
    }

    /** @return HasMany<RelationshipInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(RelationshipInvitation::class);
    }

    /** @return HasMany<TemperatureCheckIn, $this> */
    public function temperatureCheckIns(): HasMany
    {
        return $this->hasMany(TemperatureCheckIn::class);
    }

    /** @return HasMany<SharedMoment, $this> */
    public function sharedMoments(): HasMany
    {
        return $this->hasMany(SharedMoment::class);
    }

    /** @return HasMany<SecretMissionPrompt, $this> */
    public function secretMissionPrompts(): HasMany
    {
        return $this->hasMany(SecretMissionPrompt::class);
    }

    /** @return HasMany<SecretMission, $this> */
    public function secretMissions(): HasMany
    {
        return $this->hasMany(SecretMission::class);
    }

    /** @return HasMany<RelationshipPromptTagRule, $this> */
    public function promptTagRules(): HasMany
    {
        return $this->hasMany(RelationshipPromptTagRule::class);
    }

    /** @return HasOne<PromptRound, $this> */
    public function activeRound(): HasOne
    {
        return $this->hasOne(PromptRound::class)
            ->where('status', PromptRoundStatus::Active->value)
            ->latestOfMany('available_at');
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }
}
