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

            $this->addError('photos', __('This moment could not be saved. Please try again.'));

            return;
        }

        $this->reset('body', 'photos');
        $this->intensity = 5;
        unset($this->moments);
        Flux::modal('log-shared-moment')->close();
        Flux::toast(variant: 'success', text: __('Moment saved.'));
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
        Flux::toast(variant: 'success', text: __('Moment updated.'));
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
        Flux::toast(variant: 'success', text: __('Moment deleted.'));
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
        return $this->visibleMomentsQuery()
            ->with(['author', 'photos', 'comments.author'])
            ->latest()
            ->limit($this->showFeed ? 24 : 1)
            ->get();
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
    <div class="prompt-surface p-6 sm:p-7">
        <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-pink-600 dark:text-pink-300">
            <flux:icon.sparkles class="size-3.5" />
            <span>{{ __('Moments') }}</span>
        </div>
        <flux:heading size="lg" class="mt-2 tracking-tight">{{ __('Keep something from your day') }}</flux:heading>
        <flux:text class="mt-1 max-w-xl">
            {{ $this->relationship
                ? __('A private, shared log for whatever feels worth remembering.')
                : __('Keep moments for yourself now. Your full history will be shared once you pair with your partner.') }}
        </flux:text>

        <flux:modal.trigger name="log-shared-moment">
            <flux:button variant="primary" icon="plus" class="mt-5 w-full justify-center">
                {{ __('Log a moment') }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    @if (! $showFeed && $this->moments->isNotEmpty())
        @php
            $latestMoment = $this->moments->first();
            $coverPhoto = $latestMoment->photos->first();
        @endphp

        <a
            href="{{ route('moments') }}"
            wire:navigate.hover
            class="group relative block min-h-80 overflow-hidden rounded-[1.75rem] bg-gradient-to-br from-pink-500 via-pink-600 to-pink-900 shadow-[0_18px_50px_rgba(41,38,46,0.16)] ring-1 ring-black/5"
        >
            @if ($coverPhoto)
                <img
                    src="{{ route('moment-photos.show', $coverPhoto) }}"
                    alt="{{ __('Latest moment shared by :name', ['name' => $latestMoment->author->name]) }}"
                    class="absolute inset-0 size-full object-cover transition duration-700 ease-out group-hover:scale-[1.025]"
                >
            @endif

            <div class="absolute inset-0 bg-gradient-to-b from-black/20 via-black/10 to-black/85"></div>

            <div class="relative flex min-h-80 flex-col justify-between p-6 text-white sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <span class="rounded-full bg-black/20 px-3 py-1.5 text-[0.6875rem] font-semibold uppercase tracking-[0.14em] ring-1 ring-white/20 backdrop-blur-md">
                        {{ __('Latest moment') }}
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
                            {{ __('View moments') }}
                            <flux:icon.arrow-right class="size-4 transition group-hover:translate-x-0.5" />
                        </span>
                    </div>
                </div>
            </div>
        </a>
    @elseif ($showFeed)
        @if ($this->moments->isNotEmpty())
            <div class="flex items-center justify-between px-1 pt-2">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500">{{ __('Recent moments') }}</p>
                <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ trans_choice(':count post|:count posts', $this->moments->count(), ['count' => $this->moments->count()]) }}</span>
            </div>

            <div class="space-y-4" data-page-stagger>
                @foreach ($this->moments as $moment)
                    <article class="prompt-surface p-5 sm:p-6" wire:key="moment-{{ $moment->id }}">
                        <header class="flex items-center gap-3">
                            <flux:avatar size="sm" :name="$moment->author->name" :initials="$moment->author->initials()" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $moment->author->name }}</p>
                                <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">{{ $moment->created_at->diffForHumans() }}</p>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <div class="rounded-full bg-pink-50 px-3 py-1.5 text-xs font-semibold text-pink-700 ring-1 ring-pink-100 dark:bg-pink-500/10 dark:text-pink-300 dark:ring-pink-400/15">
                                    {{ $this->intensityLabel($moment->intensity) }} · {{ $moment->intensity }}
                                </div>

                                @if ($moment->user_id === auth()->id())
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        icon="pencil-square"
                                        aria-label="{{ __('Edit moment') }}"
                                        wire:click="startEditingMoment({{ $moment->id }})"
                                    />
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        aria-label="{{ __('Delete moment') }}"
                                        wire:click="deleteMoment({{ $moment->id }})"
                                        wire:confirm="{{ __('Delete this moment, its photos, and all comments? This cannot be undone.') }}"
                                    />
                                @endif
                            </div>
                        </header>

                        @if ($moment->body)
                            <p class="mt-4 whitespace-pre-line text-[0.9375rem] leading-6 text-zinc-700 dark:text-zinc-200">{{ $moment->body }}</p>
                        @endif

                        @if ($moment->photos->isNotEmpty())
                            <div class="mt-4 grid gap-2 overflow-hidden rounded-2xl {{ $moment->photos->count() === 1 ? 'grid-cols-1' : 'grid-cols-2' }}">
                                @foreach ($moment->photos as $photo)
                                    <img
                                        src="{{ route('moment-photos.show', $photo) }}"
                                        alt="{{ __('Photo shared by :name', ['name' => $moment->author->name]) }}"
                                        class="w-full object-cover {{ $moment->photos->count() === 1 ? 'max-h-[30rem] aspect-[4/3]' : 'aspect-square' }}"
                                        loading="lazy"
                                    >
                                @endforeach
                            </div>
                        @endif

                        <div class="mt-5 border-t border-zinc-200/70 pt-5 dark:border-white/8">
                            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.12em] text-zinc-400 dark:text-zinc-500">
                                <flux:icon.chat-bubble-left-right class="size-3.5" />
                                <span>{{ trans_choice(':count comment|:count comments', $moment->comments->count(), ['count' => $moment->comments->count()]) }}</span>
                            </div>

                            @if ($moment->comments->isNotEmpty())
                                <div class="mt-4 space-y-4">
                                    @foreach ($moment->comments as $comment)
                                        <div class="flex items-start gap-3" wire:key="moment-comment-{{ $comment->id }}">
                                            <flux:avatar size="xs" :name="$comment->author->name" :initials="$comment->author->initials()" />
                                            <div class="min-w-0 flex-1 rounded-2xl bg-zinc-50/80 px-4 py-3 ring-1 ring-zinc-200/70 dark:bg-white/[0.035] dark:ring-white/8">
                                                <div class="flex items-center justify-between gap-3">
                                                    <div class="min-w-0">
                                                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $comment->author->name }}</span>
                                                        <span class="ms-1.5 text-xs text-zinc-400 dark:text-zinc-500">{{ $comment->created_at->diffForHumans() }}</span>
                                                    </div>

                                                    @if ($comment->user_id === auth()->id() && $editingCommentId !== $comment->id)
                                                        <div class="flex shrink-0 items-center">
                                                            <flux:button type="button" size="sm" variant="ghost" icon="pencil-square" aria-label="{{ __('Edit comment') }}" wire:click="startEditingComment({{ $comment->id }})" />
                                                            <flux:button type="button" size="sm" variant="ghost" icon="trash" aria-label="{{ __('Delete comment') }}" wire:click="deleteComment({{ $comment->id }})" wire:confirm="{{ __('Delete this comment?') }}" />
                                                        </div>
                                                    @endif
                                                </div>

                                                @if ($editingCommentId === $comment->id)
                                                    <form wire:submit="updateComment" class="mt-3 space-y-3">
                                                        <flux:textarea wire:model="editingCommentBody" label="{{ __('Edit comment') }}" label:sr-only rows="3" maxlength="2000" />
                                                        <flux:error name="editingCommentBody" />
                                                        <div class="flex justify-end gap-2">
                                                            <flux:button type="button" size="sm" variant="ghost" wire:click="cancelEditingComment">{{ __('Cancel') }}</flux:button>
                                                            <flux:button type="submit" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                                                        </div>
                                                    </form>
                                                @else
                                                    <p class="mt-1.5 whitespace-pre-line text-sm leading-6 text-zinc-700 dark:text-zinc-200">{{ $comment->body }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <form wire:submit="addComment({{ $moment->id }})" class="mt-4">
                                <div class="flex items-start gap-3">
                                    <flux:avatar size="xs" :name="auth()->user()->name" :initials="auth()->user()->initials()" />
                                    <div class="min-w-0 flex-1">
                                        <flux:textarea
                                            wire:model="commentBodies.{{ $moment->id }}"
                                            label="{{ __('Add a comment') }}"
                                            label:sr-only
                                            rows="2"
                                            maxlength="2000"
                                            placeholder="{{ __('Leave a comment…') }}"
                                        />
                                        <flux:error :name="'commentBodies.'.$moment->id" class="mt-2" />
                                        <div class="mt-2 flex justify-end">
                                            <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane">{{ __('Comment') }}</flux:button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <div class="prompt-surface-muted flex min-h-64 items-center justify-center p-8 text-center">
                <div class="max-w-sm">
                    <flux:icon.sparkles class="mx-auto size-7 text-zinc-400" />
                    <flux:heading class="mt-4">{{ __('Nothing here yet') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Your shared moments will collect here.') }}</flux:text>
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
                <flux:heading size="xl" class="tracking-tight">{{ __('Log a moment') }}</flux:heading>
                <flux:subheading>{{ __('Add as much or as little context as you want.') }}</flux:subheading>
            </div>

            <div>
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ __('Intensity') }}</p>
                        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ $this->intensityLabel($intensity) }}</p>
                    </div>
                    <p class="text-lg font-semibold text-pink-600 dark:text-pink-300">{{ $intensity }} / 10</p>
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
                <label class="group flex cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed border-zinc-300 bg-zinc-50/70 px-5 py-7 text-center transition hover:border-pink-300 hover:bg-pink-50/50 dark:border-white/15 dark:bg-black/10 dark:hover:border-pink-400/50 dark:hover:bg-pink-500/5">
                    <flux:icon.photo class="size-6 text-zinc-400 transition group-hover:text-pink-500" />
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
                                    <span class="mt-1 text-[9px] font-semibold uppercase tracking-wider text-pink-500">{{ __('Converts to JPEG') }}</span>
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
                    <span wire:loading.remove wire:target="logMoment">{{ __('Save moment') }}</span>
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
                <flux:heading size="xl" class="tracking-tight">{{ __('Edit moment') }}</flux:heading>
                <flux:subheading>{{ __('Adjust the note or how strongly this moment felt.') }}</flux:subheading>
            </div>

            <div>
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ __('Intensity') }}</p>
                        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ $this->intensityLabel($editIntensity) }}</p>
                    </div>
                    <p class="text-lg font-semibold text-pink-600 dark:text-pink-300">{{ $editIntensity }} / 10</p>
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
