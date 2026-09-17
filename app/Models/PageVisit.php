<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $route_name
 * @property string $route_uri
 * @property Carbon $visited_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'route_name', 'route_uri', 'visited_at'])]
class PageVisit extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<PageVisit> $query */
    public function scopeRecent(Builder $query): void
    {
        $query->latest('visited_at')->latest('id');
    }

    public function label(): string
    {
        return match ($this->route_name) {
            'dashboard' => 'Home',
            'history' => 'History',
            'library' => 'Library',
            'missions' => 'Secret Missions',
            'moments' => 'Posts',
            'moments.show' => 'Post',
            'prompts.show' => 'Daily prompt',
            'extracurriculars.show' => 'Extracurricular',
            'invitations.accept' => 'Invitation',
            'settings.index' => 'Settings',
            'profile.edit' => 'Profile settings',
            'security.edit' => 'Security settings',
            'relationship.edit' => 'Relationship settings',
            'notifications.edit' => 'Notification settings',
            'appearance.edit' => 'Appearance settings',
            'prompt-schedule.edit' => 'Prompt schedule',
            'prompt-libraries.edit' => 'Prompt libraries',
            'activity-log.index' => 'Activity log',
            default => Str::headline(str_replace('.', ' ', $this->route_name)),
        };
    }

    public function icon(): string
    {
        return match (true) {
            $this->route_name === 'dashboard' => 'home',
            $this->route_name === 'history' => 'clock',
            str_starts_with($this->route_name, 'moments') => 'sparkles',
            $this->route_name === 'library' => 'photo',
            $this->route_name === 'missions' => 'gift',
            str_starts_with($this->route_name, 'prompts') => 'chat-bubble-left-right',
            str_starts_with($this->route_name, 'extracurriculars') => 'bolt',
            str_contains($this->route_name, 'settings') || str_contains($this->route_name, 'edit') => 'cog-6-tooth',
            default => 'document',
        };
    }
}
