<?php

use App\Models\Relationship;
use App\Models\SharedMoment;
use App\Models\SharedMomentComment;
use App\Models\User;
use App\Services\PhotoStorage;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public int $intensity = 5;

    public string $body = '';

    public bool $showFeed = false;

    public bool $showComposer = true;

    /** @var array<int, TemporaryUploadedFile> */
    public array $photos = [];

    /** @var array<int, string> */
    public array $commentBodies = [];

    public ?int $editingMomentId = null;

    public int $editIntensity = 5;

    public string $editBody = '';

    public ?int $editingCommentId = null;

    public string $editingCommentBody = '';

    public function logMoment(PhotoStorage $photoStorage): void
    {
        $validated = $this->validate([
            'intensity' => ['required', 'integer', 'between:1,10'],
            'body' => ['nullable', 'string', 'max:5000'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['file', 'extensions:jpg,jpeg,png,gif,webp,tif,tiff,dng,heic,heif', 'max:512000'],
        ], [
            'photos.max' => __('Choose up to six photos.'),
            'photos.*.extensions' => __('Use a JPG, PNG, GIF, WebP, TIFF, Apple ProRAW (DNG), or HEIC photo.'),
            'photos.*.max' => __('Each photo must be 500 MB or smaller.'),
        ]);

        $relationship = $this->relationship;
        $user = $this->user();

        $storedPaths = [];
        $mediaDisk = (string) config('filesystems.media_disk', 'homelab_cloud');
        $storageOwner = $relationship ? "relationships/{$relationship->id}" : "users/{$user->id}";

        try {
            DB::transaction(function () use ($relationship, $user, $validated, $mediaDisk, $storageOwner, $photoStorage, &$storedPaths): void {
                $moment = SharedMoment::query()->create([
                    'relationship_id' => $relationship?->id,
                    'user_id' => $user->id,
                    'intensity' => $validated['intensity'],
                    'body' => filled($validated['body']) ? trim($validated['body']) : null,
                ]);

                foreach ($this->photos as $position => $photo) {
                    $stored = $photoStorage->store($photo, "moments/{$storageOwner}/{$moment->id}", $mediaDisk);

                    $storedPaths[] = $stored['path'];
                    $moment->photos()->create([
                        'disk' => $mediaDisk,
                        'path' => $stored['path'],
                        'original_name' => $photo->getClientOriginalName(),
                        'mime_type' => $stored['mime_type'],
                        'size' => $stored['size'],
                        'position' => $position + 1,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            report($exception);

            foreach ($storedPaths as $storedPath) {
                Storage::disk($mediaDisk)->delete($storedPath);
            }

            $this->addError('photos', __('This post could not be created. Please try again.'));

            return;
        }

        $this->reset('body', 'photos');
        $this->intensity = 5;
        unset($this->moments);
        Flux::modal('log-shared-moment')->close();
        Flux::toast(variant: 'success', text: __('Post created.'));
    }

    public function startEditingMoment(int $momentId): void
    {
        $moment = $this->momentForRelationship($momentId);
        abort_unless($moment->user_id === $this->user()->id, 403);

        $this->editingMomentId = $moment->id;
        $this->editIntensity = $moment->intensity;
        $this->editBody = $moment->body ?? '';
        $this->resetValidation(['editIntensity', 'editBody']);
        Flux::modal('edit-shared-moment')->show();
    }

    public function updateMoment(): void
    {
        $validated = $this->validate([
            'editIntensity' => ['required', 'integer', 'between:1,10'],
            'editBody' => ['nullable', 'string', 'max:5000'],
        ]);
        abort_unless($this->editingMomentId, 404);
        $moment = $this->momentForRelationship($this->editingMomentId);
        abort_unless($moment->user_id === $this->user()->id, 403);

        $moment->update([
            'intensity' => $validated['editIntensity'],
            'body' => filled($validated['editBody']) ? trim($validated['editBody']) : null,
        ]);

        $this->reset('editingMomentId', 'editBody');
        $this->editIntensity = 5;
        unset($this->moments);
        Flux::modal('edit-shared-moment')->close();
        Flux::toast(variant: 'success', text: __('Post updated.'));
    }

    public function deleteMoment(int $momentId): void
    {
        $moment = $this->momentForRelationship($momentId);
        abort_unless($moment->user_id === $this->user()->id, 403);
        $photos = $moment->photos()->get(['disk', 'path']);

        $moment->delete();

        foreach ($photos as $photo) {
            Storage::disk($photo->disk)->delete($photo->path);
        }

        unset($this->commentBodies[$momentId], $this->moments);
        Flux::toast(variant: 'success', text: __('Post deleted.'));
    }

    public function addComment(int $momentId): void
    {
        $validated = $this->validate([
            "commentBodies.{$momentId}" => ['required', 'string', 'max:2000'],
        ], [
            "commentBodies.{$momentId}.required" => __('Write a comment first.'),
        ]);
        $moment = $this->momentForRelationship($momentId);

        $moment->comments()->create([
            'user_id' => $this->user()->id,
            'body' => trim($validated['commentBodies'][$momentId]),
        ]);

        $this->commentBodies[$momentId] = '';
        unset($this->moments);
        Flux::toast(variant: 'success', text: __('Comment added.'));
    }

    public function startEditingComment(int $commentId): void
    {
        $comment = $this->commentForRelationship($commentId);
        abort_unless($comment->user_id === $this->user()->id, 403);

        $this->editingCommentId = $comment->id;
        $this->editingCommentBody = $comment->body;
        $this->resetValidation('editingCommentBody');
    }

    public function cancelEditingComment(): void
    {
        $this->reset('editingCommentId', 'editingCommentBody');
        $this->resetValidation('editingCommentBody');
    }

    public function updateComment(): void
    {
        $validated = $this->validate([
            'editingCommentBody' => ['required', 'string', 'max:2000'],
        ]);
        abort_unless($this->editingCommentId, 404);
        $comment = $this->commentForRelationship($this->editingCommentId);
        abort_unless($comment->user_id === $this->user()->id, 403);

        $comment->update(['body' => trim($validated['editingCommentBody'])]);

        $this->cancelEditingComment();
        unset($this->moments);
        Flux::toast(variant: 'success', text: __('Comment updated.'));
    }

    public function deleteComment(int $commentId): void
    {
        $comment = $this->commentForRelationship($commentId);
        abort_unless($comment->user_id === $this->user()->id, 403);
        $comment->delete();

        if ($this->editingCommentId === $commentId) {
            $this->cancelEditingComment();
        }

        unset($this->moments);
        Flux::toast(variant: 'success', text: __('Comment deleted.'));
    }

    #[Computed]
    public function relationship(): ?Relationship
    {
        return $this->user()->relationships()->first();
    }

    /** @return Collection<int, SharedMoment> */
    #[Computed]
    public function moments(): Collection
    {
        $query = $this->visibleMomentsQuery()
            ->with(['author', 'photos', 'comments.author'])
            ->latest();

        if (! $this->showFeed) {
            $query->limit(1);
        }

        return $query->get();
    }

    public function intensityLabel(int $value): string
    {
        return match (true) {
            $value <= 2 => __('Quiet'),
            $value <= 4 => __('Light'),
            $value <= 6 => __('Notable'),
            $value <= 8 => __('Strong'),
            default => __('Intense'),
        };
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function momentForRelationship(int $momentId): SharedMoment
    {
        return $this->visibleMomentsQuery()->findOrFail($momentId);
    }

    private function commentForRelationship(int $commentId): SharedMomentComment
    {
        $comment = SharedMomentComment::query()->with('moment')->findOrFail($commentId);
        abort_unless($this->canViewMoment($comment->moment), 404);

        return $comment;
    }

    /** @return Builder<SharedMoment> */
    private function visibleMomentsQuery(): Builder
    {
        $user = $this->user();
        $relationship = $this->relationship;
        $personalAuthorIds = $relationship
            ? $relationship->members()->pluck('users.id')->all()
            : [$user->id];

        return SharedMoment::query()->where(function (Builder $query) use ($relationship, $personalAuthorIds): void {
            $query->where(function (Builder $personal) use ($personalAuthorIds): void {
                $personal->whereNull('relationship_id')->whereIn('user_id', $personalAuthorIds);
            });

            if ($relationship) {
                $query->orWhere('relationship_id', $relationship->id);
            }
        });
    }

    private function canViewMoment(SharedMoment $moment): bool
    {
        if ($moment->relationship_id === null) {
            if ($moment->user_id === $this->user()->id) {
                return true;
            }

            return $this->relationship?->members()->whereKey($moment->user_id)->exists() ?? false;
        }

        return $moment->relationship_id === $this->relationship?->id;
    }
}; ?>

@php
    $intensityProgress = (($intensity - 1) / 9) * 100;
    $editIntensityProgress = (($editIntensity - 1) / 9) * 100;
@endphp

<section class="space-y-4">
    @if ($showComposer)
        <div class="prompt-surface p-6 sm:p-7">
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-violet-600 dark:text-violet-300">
                <flux:icon.sparkles class="size-3.5" />
                <span>{{ __('Posts') }}</span>
            </div>
            <flux:heading size="lg" class="mt-2 tracking-tight">{{ __('Keep something from your day') }}</flux:heading>
            <flux:text class="mt-1 max-w-xl">
                {{ $this->relationship
                    ? __('A private, shared feed for whatever feels worth posting.')
                    : __('Create posts for yourself now. Your full history will be shared once you pair with your partner.') }}
            </flux:text>

            <flux:modal.trigger name="log-shared-moment">
                <flux:button variant="primary" icon="plus" class="mt-5 w-full justify-center">
                    {{ __('Create post') }}
                </flux:button>
            </flux:modal.trigger>
        </div>
    @endif

    @if (! $showFeed && $this->moments->isNotEmpty())
        @php
            $latestMoment = $this->moments->first();
            $coverPhoto = $latestMoment->photos->first();
        @endphp

        <a
            href="{{ route('moments') }}"
            wire:navigate.hover
            class="group relative block min-h-80 overflow-hidden rounded-[1.75rem] bg-gradient-to-br from-violet-500 via-violet-600 to-violet-900 shadow-[0_18px_50px_rgba(41,38,46,0.16)] ring-1 ring-black/5"
        >
            @if ($coverPhoto)
                <img
                    src="{{ route('moment-photos.show', $coverPhoto) }}"
                    alt="{{ __('Latest post shared by :name', ['name' => $latestMoment->author->name]) }}"
                    class="absolute inset-0 size-full object-cover transition duration-700 ease-out group-hover:scale-[1.025]"
                >
            @endif

            <div class="absolute inset-0 bg-gradient-to-b from-black/20 via-black/10 to-black/85"></div>

            <div class="relative flex min-h-80 flex-col justify-between p-6 text-white sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <span class="rounded-full bg-black/20 px-3 py-1.5 text-[0.6875rem] font-semibold uppercase tracking-[0.14em] ring-1 ring-white/20 backdrop-blur-md">
                        {{ __('Latest post') }}
                    </span>
                    @if ($latestMoment->photos->count() > 1)
                        <span class="flex items-center gap-1.5 rounded-full bg-black/20 px-3 py-1.5 text-xs font-medium ring-1 ring-white/20 backdrop-blur-md">
                            <flux:icon.photo class="size-3.5" />
                            {{ $latestMoment->photos->count() }}
                        </span>
                    @endif
                </div>

                <div>
                    <div class="mb-3 flex items-center gap-2.5">
                        <flux:avatar size="sm" :name="$latestMoment->author->name" :initials="$latestMoment->author->initials()" />
                        <div>
                            <p class="text-sm font-medium">{{ $latestMoment->author->name }}</p>
                            <p class="text-xs text-white/65">{{ $latestMoment->created_at->diffForHumans() }}</p>
                        </div>
                    </div>

                    @if ($latestMoment->body)
                        <p class="line-clamp-3 max-w-xl text-xl font-medium leading-7 tracking-[-0.02em] text-white sm:text-2xl">
                            {{ $latestMoment->body }}
                        </p>
                    @endif

                    <div class="mt-4 flex items-center justify-between gap-4">
                        <span class="text-sm font-medium text-white/75">
                            {{ $this->intensityLabel($latestMoment->intensity) }} · {{ $latestMoment->intensity }} / 10
                        </span>
                        <span class="flex items-center gap-1.5 text-sm font-medium">
                            {{ __('View posts') }}
                            <flux:icon.arrow-right class="size-4 transition group-hover:translate-x-0.5" />
                        </span>
                    </div>
                </div>
            </div>
        </a>
    @elseif ($showFeed)
        @if ($this->moments->isNotEmpty())
            <div class="flex items-center justify-between px-1 pt-2">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500">{{ __('All posts') }}</p>
                <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ trans_choice(':count post|:count posts', $this->moments->count(), ['count' => $this->moments->count()]) }}</span>
            </div>

            <div class="grid gap-5 sm:grid-cols-2" data-page-stagger>
                @foreach ($this->moments as $moment)
                    @php($coverPhoto = $moment->photos->first())
                    <a
                        href="{{ route('moments.show', $moment) }}"
                        wire:navigate.hover
                        wire:key="moment-{{ $moment->id }}"
                        class="group relative flex min-h-[24rem] overflow-hidden rounded-[1.75rem] bg-gradient-to-br from-violet-500 via-violet-600 to-violet-950 shadow-[0_18px_50px_rgba(41,38,46,0.14)] ring-1 ring-black/5 transition duration-500 hover:-translate-y-1 hover:shadow-[0_24px_65px_rgba(41,38,46,0.2)] sm:min-h-[28rem]"
                    >
                        @if ($coverPhoto)
                            <img
                                src="{{ route('moment-photos.show', $coverPhoto) }}"
                                alt="{{ __('Post shared by :name', ['name' => $moment->author->name]) }}"
                                class="absolute inset-0 size-full object-cover transition duration-700 ease-out group-hover:scale-[1.035]"
                                loading="lazy"
                            >
                        @endif

                        <div class="absolute inset-0 bg-gradient-to-b from-black/15 via-black/5 to-black/90"></div>

                        <div class="relative flex min-h-full w-full flex-col justify-between p-5 text-white sm:p-6">
                            <div class="flex items-start justify-between gap-3">
                                <span class="rounded-full bg-black/20 px-3 py-1.5 text-[0.6875rem] font-semibold uppercase tracking-[0.14em] ring-1 ring-white/20 backdrop-blur-md">
                                    {{ $moment->created_at->format('M j, Y') }}
                                </span>
                                <span class="rounded-full bg-black/20 px-3 py-1.5 text-xs font-medium ring-1 ring-white/20 backdrop-blur-md">
                                    {{ $this->intensityLabel($moment->intensity) }} · {{ $moment->intensity }}
                                </span>
                            </div>

                            <div>
                                <div class="mb-3 flex items-center gap-2.5">
                                    <flux:avatar size="sm" :name="$moment->author->name" :initials="$moment->author->initials()" />
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold">{{ $moment->author->name }}</p>
                                        <p class="text-xs text-white/65">{{ $moment->created_at->diffForHumans() }}</p>
                                    </div>
                                </div>

                                @if ($moment->body)
                                    <p class="line-clamp-4 whitespace-pre-line text-xl font-medium leading-7 tracking-[-0.025em] sm:text-2xl sm:leading-8">{{ $moment->body }}</p>
                                @else
                                    <p class="text-xl font-medium tracking-[-0.025em] text-white/90">{{ __('A little piece of your day.') }}</p>
                                @endif

                                <div class="mt-5 flex items-center justify-between text-sm text-white/75">
                                    <div class="flex items-center gap-4">
                                        @if ($moment->photos->isNotEmpty())
                                            <span class="flex items-center gap-1.5"><flux:icon.photo class="size-4" /> {{ $moment->photos->count() }}</span>
                                        @endif
                                        <span class="flex items-center gap-1.5"><flux:icon.chat-bubble-left-right class="size-4" /> {{ $moment->comments->count() }}</span>
                                    </div>
                                    <span class="flex items-center gap-1.5 font-medium text-white">
                                        {{ __('Open') }}
                                        <flux:icon.arrow-up-right class="size-4 transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
                                    </span>
                                </div>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="prompt-surface-muted flex min-h-64 items-center justify-center p-8 text-center">
                <div class="max-w-sm">
                    <flux:icon.sparkles class="mx-auto size-7 text-zinc-400" />
                    <flux:heading class="mt-4">{{ __('Nothing here yet') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Your shared posts will collect here.') }}</flux:text>
                </div>
            </div>
        @endif
    @endif

    <flux:modal
        name="log-shared-moment"
        :show="$errors->has('intensity') || $errors->has('body') || $errors->has('photos') || $errors->has('photos.*')"
        focusable
        class="max-w-xl"
    >
        <form wire:submit="logMoment" class="space-y-6">
            <div>
                <flux:heading size="xl" class="tracking-tight">{{ __('Create post') }}</flux:heading>
                <flux:subheading>{{ __('Add as much or as little context as you want.') }}</flux:subheading>
            </div>

            <div>
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ __('Intensity') }}</p>
                        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ $this->intensityLabel($intensity) }}</p>
                    </div>
                    <p class="text-lg font-semibold text-violet-600 dark:text-violet-300">{{ $intensity }} / 10</p>
                </div>
                <input
                    type="range"
                    min="1"
                    max="10"
                    step="1"
                    wire:model.live="intensity"
                    class="temperature-slider mt-4 w-full"
                    style="--temperature-progress: {{ $intensityProgress }}%;"
                    aria-label="{{ __('Intensity') }}"
                    aria-valuetext="{{ $intensity }} out of 10, {{ $this->intensityLabel($intensity) }}"
                >
            </div>

            <flux:textarea
                wire:model="body"
                :label="__('A note')"
                :placeholder="__('What happened?')"
                rows="5"
                maxlength="5000"
            />

            <div>
                <label class="group flex cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed border-zinc-300 bg-zinc-50/70 px-5 py-7 text-center transition hover:border-violet-300 hover:bg-violet-50/50 dark:border-white/15 dark:bg-black/10 dark:hover:border-violet-400/50 dark:hover:bg-violet-500/5">
                    <flux:icon.photo class="size-6 text-zinc-400 transition group-hover:text-violet-500" />
                    <span class="mt-2 text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ __('Add photos') }}</span>
                    <span class="mt-1 text-xs text-zinc-400">{{ __('Up to six photos, 500 MB each') }}</span>
                    <input wire:model="photos" type="file" accept="image/*,.dng,.tif,.tiff,.heic,.heif" multiple class="sr-only">
                </label>

                <div wire:loading wire:target="photos" class="mt-2 text-xs text-zinc-400">{{ __('Preparing photos…') }}</div>

                @if (count($photos) > 0)
                    <div class="mt-3 grid grid-cols-3 gap-2">
                        @foreach ($photos as $photo)
                            @if (in_array(strtolower($photo->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true))
                                <img src="{{ $photo->temporaryUrl() }}" alt="{{ __('Selected photo preview') }}" class="aspect-square w-full rounded-xl object-cover">
                            @else
                                <div class="flex aspect-square w-full flex-col items-center justify-center rounded-xl bg-zinc-100 p-2 text-center text-zinc-500 dark:bg-white/8 dark:text-zinc-300">
                                    <flux:icon.photo class="size-5" />
                                    <span class="mt-1 line-clamp-2 text-[11px]">{{ $photo->getClientOriginalName() }}</span>
                                    <span class="mt-1 text-[9px] font-semibold uppercase tracking-wider text-violet-500">{{ __('Converts to JPEG') }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif

                <flux:error name="photos" class="mt-2" />
                @foreach ($errors->get('photos.*') as $photoErrors)
                    @foreach ($photoErrors as $photoError)
                        <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $photoError }}</p>
                    @endforeach
                @endforeach
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="plus" wire:loading.attr="disabled" wire:target="logMoment">
                    <span wire:loading.remove wire:target="logMoment">{{ __('Create post') }}</span>
                    <span wire:loading wire:target="logMoment">{{ __('Saving…') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal
        name="edit-shared-moment"
        :show="$errors->has('editIntensity') || $errors->has('editBody')"
        focusable
        class="max-w-xl"
    >
        <form wire:submit="updateMoment" class="space-y-6">
            <div>
                <flux:heading size="xl" class="tracking-tight">{{ __('Edit post') }}</flux:heading>
                <flux:subheading>{{ __('Adjust the post text or its intensity.') }}</flux:subheading>
            </div>

            <div>
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ __('Intensity') }}</p>
                        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ $this->intensityLabel($editIntensity) }}</p>
                    </div>
                    <p class="text-lg font-semibold text-violet-600 dark:text-violet-300">{{ $editIntensity }} / 10</p>
                </div>
                <input
                    type="range"
                    min="1"
                    max="10"
                    step="1"
                    wire:model.live="editIntensity"
                    class="temperature-slider mt-4 w-full"
                    style="--temperature-progress: {{ $editIntensityProgress }}%;"
                    aria-label="{{ __('Intensity') }}"
                    aria-valuetext="{{ $editIntensity }} out of 10, {{ $this->intensityLabel($editIntensity) }}"
                >
                <flux:error name="editIntensity" class="mt-2" />
            </div>

            <flux:textarea
                wire:model="editBody"
                :label="__('A note')"
                :placeholder="__('What happened?')"
                rows="6"
                maxlength="5000"
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save changes') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
