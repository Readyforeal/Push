<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AppBackgroundMode;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_admin
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'is_admin', 'background_mode', 'background_photo_id', 'background_image_disk', 'background_image_path', 'background_image_mime_type'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'background_mode' => AppBackgroundMode::class,
            'background_photo_id' => 'integer',
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    public function firstName(): string
    {
        return Str::before(trim($this->name), ' ');
    }

    /** @return BelongsToMany<Relationship, $this> */
    public function relationships(): BelongsToMany
    {
        return $this->belongsToMany(Relationship::class, 'relationship_members')
            ->withPivot(['joined_at'])
            ->withTimestamps();
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

    /** @return HasMany<SharedMomentComment, $this> */
    public function sharedMomentComments(): HasMany
    {
        return $this->hasMany(SharedMomentComment::class);
    }

    /** @return HasMany<SecretMissionPrompt, $this> */
    public function requestedSecretMissions(): HasMany
    {
        return $this->hasMany(SecretMissionPrompt::class, 'beneficiary_user_id');
    }

    /** @return HasMany<SecretMission, $this> */
    public function assignedSecretMissions(): HasMany
    {
        return $this->hasMany(SecretMission::class, 'assignee_user_id');
    }

    /** @return BelongsTo<RoundPhoto, $this> */
    public function backgroundPhoto(): BelongsTo
    {
        return $this->belongsTo(RoundPhoto::class, 'background_photo_id');
    }

    public function latestFavoritePhoto(): ?RoundPhoto
    {
        return PhotoSelection::query()
            ->whereHas('task', fn ($query) => $query->where('user_id', $this->id))
            ->with('photo')
            ->latest('id')
            ->first()
            ?->photo;
    }

    public function appBackgroundPhoto(): ?RoundPhoto
    {
        return match ($this->background_mode) {
            AppBackgroundMode::None => null,
            AppBackgroundMode::Photo => $this->backgroundPhoto,
            AppBackgroundMode::Upload => null,
            default => $this->latestFavoritePhoto(),
        };
    }

    public function appBackgroundUrl(): ?string
    {
        if ($this->background_mode === AppBackgroundMode::Upload) {
            return $this->background_image_path
                ? route('background.show', ['v' => substr(hash('sha256', $this->background_image_path), 0, 12)])
                : null;
        }

        $photo = $this->appBackgroundPhoto();

        return $photo ? route('round-photos.show', $photo) : null;
    }
}
