<?php

use App\Models\SharedMoment;
use App\Models\SharedMomentComment;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Post')] class extends Component
{
    public SharedMoment $moment;

    public string $commentBody = '';

    public int $editIntensity = 5;

    public string $editBody = '';

    public ?int $editingCommentId = null;

    public string $editingCommentBody = '';

    public function mount(SharedMoment $moment): void
    {
        Gate::authorize('view', $moment);
        $this->moment = $moment;
        $this->editIntensity = $moment->intensity;
        $this->editBody = $moment->body ?? '';
        $this->refreshMoment();
    }

    public function addComment(): void
    {
        Gate::authorize('view', $this->moment);
        $validated = $this->validate([
            'commentBody' => ['required', 'string', 'max:2000'],
        ], [
            'commentBody.required' => __('Write a comment first.'),
        ]);

        $this->moment->comments()->create([
            'user_id' => Auth::id(),
            'body' => trim($validated['commentBody']),
        ]);

        $this->reset('commentBody');
        $this->refreshMoment();
        Flux::toast(variant: 'success', text: __('Comment added.'));
    }

    public function updateMoment(): void
    {
        abort_unless($this->moment->user_id === Auth::id(), 403);
        $validated = $this->validate([
            'editIntensity' => ['required', 'integer', 'between:1,10'],
            'editBody' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->moment->update([
            'intensity' => $validated['editIntensity'],
            'body' => filled($validated['editBody']) ? trim($validated['editBody']) : null,
        ]);

        $this->refreshMoment();
        Flux::modal('edit-moment-detail')->close();
        Flux::toast(variant: 'success', text: __('Post updated.'));
    }

    public function deleteMoment(): void
    {
        abort_unless($this->moment->user_id === Auth::id(), 403);
        $photos = $this->moment->photos()->get(['disk', 'path']);
        $this->moment->delete();

        foreach ($photos as $photo) {
            Storage::disk($photo->disk)->delete($photo->path);
        }

        $this->redirectRoute('moments', navigate: true);
    }

    public function startEditingComment(int $commentId): void
    {
        $comment = $this->comment($commentId);
        abort_unless($comment->user_id === Auth::id(), 403);
        $this->editingCommentId = $comment->id;
        $this->editingCommentBody = $comment->body;
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
        $comment = $this->comment($this->editingCommentId);
        abort_unless($comment->user_id === Auth::id(), 403);
        $comment->update(['body' => trim($validated['editingCommentBody'])]);

        $this->cancelEditingComment();
        $this->refreshMoment();
    }

    public function deleteComment(int $commentId): void
    {
        $comment = $this->comment($commentId);
        abort_unless($comment->user_id === Auth::id(), 403);
        $comment->delete();
        $this->refreshMoment();
    }

    private function comment(int $commentId): SharedMomentComment
    {
        return $this->moment->comments()->findOrFail($commentId);
    }

    private function refreshMoment(): void
    {
        $this->moment->refresh()->load(['author', 'photos', 'comments.author']);
    }
}; ?>

@php
    $intensityLabel = match (true) {
        $moment->intensity <= 2 => __('Quiet'),
        $moment->intensity <= 4 => __('Light'),
        $moment->intensity <= 6 => __('Notable'),
        $moment->intensity <= 8 => __('Strong'),
        default => __('Intense'),
    };
@endphp

<div
    data-post-page
    class="mx-auto w-full max-w-5xl pb-48 lg:pb-32"
>
    <div class="sticky top-[calc(env(safe-area-inset-top,0px)+0.5rem)] z-40 h-0 -mx-2 px-2">
        <div class="post-compact-header flex items-center gap-2.5 rounded-full border border-white/60 bg-white/72 p-2.5 shadow-lg shadow-zinc-950/10 backdrop-blur-2xl dark:border-white/10 dark:bg-zinc-950/72 dark:shadow-black/35">
            <a href="{{ route('moments') }}" wire:navigate.hover class="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-white/65 text-zinc-800 shadow-sm ring-1 ring-black/5 backdrop-blur-xl transition hover:bg-violet-100 hover:text-violet-700 dark:bg-white/8 dark:text-white dark:ring-white/10 dark:hover:bg-violet-500/15 dark:hover:text-violet-200" aria-label="{{ __('Back to posts') }}">
                <flux:icon.arrow-left class="size-5" />
            </a>

            <div class="flex min-w-0 flex-1 items-center gap-2.5 pe-3">
                <flux:avatar circle :name="$moment->author->name" :initials="$moment->author->initials()" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-zinc-950 dark:text-white">
                        {{ $moment->body ?: __('A little piece of your day.') }}
                    </p>
                    <div class="mt-1.5 flex items-center gap-2" aria-label="{{ __('Intensity: :intensity out of 10', ['intensity' => $moment->intensity]) }}">
                        <span class="shrink-0 text-[0.625rem] font-semibold tabular-nums text-violet-600 dark:text-violet-300">{{ $moment->intensity }}/10</span>
                        <div class="h-1 min-w-0 flex-1 overflow-hidden rounded-full bg-zinc-200/80 dark:bg-white/10">
                            <div
                                class="h-full rounded-full bg-gradient-to-r from-violet-400 via-violet-500 to-fuchsia-500 shadow-[0_0_8px_rgba(139,92,246,0.45)]"
                                style="width: {{ $moment->intensity * 10 }}%;"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <header data-post-header class="relative -mx-2 px-2 pt-[calc(env(safe-area-inset-top,0px)+0.5rem)]">
        <div class="post-header-shell">
            <div class="post-header-gradient"></div>

            <div class="flex items-center gap-2.5">
                <a href="{{ route('moments') }}" wire:navigate.hover class="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-white/65 text-zinc-800 shadow-sm ring-1 ring-black/5 backdrop-blur-xl transition hover:bg-violet-100 hover:text-violet-700 dark:bg-white/8 dark:text-white dark:ring-white/10 dark:hover:bg-violet-500/15 dark:hover:text-violet-200" aria-label="{{ __('Back to posts') }}">
                    <flux:icon.arrow-left class="size-5" />
                </a>

                <div class="ms-auto flex shrink-0 items-center gap-1">
                    @if ($moment->user_id === auth()->id())
                        <flux:modal.trigger name="edit-moment-detail">
                            <button type="button" class="inline-flex size-10 items-center justify-center rounded-full text-zinc-600 transition hover:bg-zinc-100 hover:text-zinc-950 dark:text-zinc-300 dark:hover:bg-white/8 dark:hover:text-white" aria-label="{{ __('Edit post') }}">
                                <flux:icon.pencil-square class="size-4.5" />
                            </button>
                        </flux:modal.trigger>
                        <button type="button" wire:click="deleteMoment" wire:confirm="{{ __('Delete this post, its photos, and all comments? This cannot be undone.') }}" class="inline-flex size-10 items-center justify-center rounded-full text-zinc-500 transition hover:bg-red-50 hover:text-red-600 dark:text-zinc-400 dark:hover:bg-red-500/10 dark:hover:text-red-300" aria-label="{{ __('Delete post') }}">
                            <flux:icon.trash class="size-4.5" />
                        </button>
                    @endif
                </div>
            </div>

            <div class="post-header-expanded">
                <div class="overflow-hidden">
                    <div class="pt-6 sm:pt-8">
                        <div class="flex items-center gap-3">
                            <flux:avatar circle size="sm" :name="$moment->author->name" :initials="$moment->author->initials()" />
                            <div>
                                <p class="text-sm font-semibold text-zinc-950 dark:text-white">{{ $moment->author->name }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $moment->created_at->format('F j, Y · g:i A') }}</p>
                            </div>
                        </div>

                        @if ($moment->body)
                            <h1 class="mt-6 max-w-4xl whitespace-pre-line text-3xl font-semibold leading-[1.08] tracking-[-0.045em] text-zinc-950 sm:text-5xl dark:text-white">{{ $moment->body }}</h1>
                        @else
                            <h1 class="mt-6 text-3xl font-semibold leading-[1.08] tracking-[-0.045em] text-zinc-950 sm:text-5xl dark:text-white">{{ __('A little piece of your day.') }}</h1>
                        @endif

                        <div class="mt-5 flex flex-wrap items-center gap-2 text-[0.6875rem] font-semibold uppercase tracking-[0.12em] text-zinc-500 dark:text-zinc-400">
                            <span class="rounded-full bg-white/45 px-3 py-1.5 ring-1 ring-black/5 backdrop-blur-xl dark:bg-white/8 dark:ring-white/10">{{ $intensityLabel }} · {{ $moment->intensity }} / 10</span>
                            <span class="rounded-full bg-white/45 px-3 py-1.5 ring-1 ring-black/5 backdrop-blur-xl dark:bg-white/8 dark:ring-white/10">{{ trans_choice(':count photo|:count photos', $moment->photos->count(), ['count' => $moment->photos->count()]) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    @if ($moment->photos->isNotEmpty())
        <section class="mt-5 space-y-5 sm:mt-7 sm:space-y-7" aria-label="{{ __('Post photos') }}">
            @foreach ($moment->photos as $photo)
                <figure class="flex w-full items-center justify-center overflow-hidden rounded-[1.75rem] bg-zinc-100 shadow-[0_18px_55px_rgba(15,23,42,0.12)] ring-1 ring-black/5 dark:bg-black dark:shadow-[0_24px_70px_rgba(0,0,0,0.4)] dark:ring-white/10 sm:rounded-[2.25rem]">
                    <img
                        src="{{ route('moment-photos.show', $photo) }}"
                        alt="{{ __('Photo shared by :name', ['name' => $moment->author->name]) }}"
                        class="mx-auto block h-auto max-h-[88svh] w-auto max-w-full object-contain"
                        loading="{{ $loop->first ? 'eager' : 'lazy' }}"
                    >
                </figure>
            @endforeach
        </section>
    @endif

    <article class="prompt-surface mt-7 p-5 sm:mt-9 sm:p-8 lg:p-10">
        <section>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-violet-600 dark:text-violet-300">{{ __('Conversation') }}</p>
                    <h2 class="mt-1 text-2xl font-semibold tracking-[-0.03em] text-zinc-950 dark:text-white">{{ trans_choice(':count comment|:count comments', $moment->comments->count(), ['count' => $moment->comments->count()]) }}</h2>
                </div>
            </div>

            @if ($moment->comments->isNotEmpty())
                <div class="mt-7 divide-y divide-zinc-200/70 dark:divide-white/8">
                    @foreach ($moment->comments as $comment)
                        <div class="py-5 first:pt-0 last:pb-0" wire:key="detail-comment-{{ $comment->id }}">
                            <div class="flex items-center gap-3">
                                <flux:avatar circle size="sm" :name="$comment->author->name" :initials="$comment->author->initials()" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-zinc-950 dark:text-white">{{ $comment->author->name }}</p>
                                            <p class="text-xs text-zinc-400">{{ $comment->created_at->diffForHumans() }}</p>
                                        </div>
                                        @if ($comment->user_id === auth()->id() && $editingCommentId !== $comment->id)
                                            <div class="flex shrink-0 items-center">
                                                <flux:button type="button" size="sm" variant="ghost" icon="pencil-square" aria-label="{{ __('Edit comment') }}" wire:click="startEditingComment({{ $comment->id }})" />
                                                <flux:button type="button" size="sm" variant="ghost" icon="trash" aria-label="{{ __('Delete comment') }}" wire:click="deleteComment({{ $comment->id }})" wire:confirm="{{ __('Delete this comment?') }}" />
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            @if ($editingCommentId === $comment->id)
                                <form wire:submit="updateComment" class="mt-4 space-y-3">
                                    <flux:textarea wire:model="editingCommentBody" label="{{ __('Edit comment') }}" label:sr-only rows="3" maxlength="2000" />
                                    <flux:error name="editingCommentBody" />
                                    <div class="flex justify-end gap-2">
                                        <flux:button type="button" size="sm" variant="ghost" wire:click="cancelEditingComment">{{ __('Cancel') }}</flux:button>
                                        <flux:button type="submit" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                                    </div>
                                </form>
                            @else
                                <p class="mt-3 whitespace-pre-line text-[0.9375rem] leading-6 text-zinc-700 dark:text-zinc-200">{{ $comment->body }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-6 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No comments yet. Leave the first little note below.') }}</p>
            @endif
        </section>
    </article>

    <form wire:submit="addComment" class="fixed inset-x-4 bottom-[calc(5.75rem+4pt)] z-40 mx-auto max-w-2xl lg:bottom-6">
        <div class="flex items-center gap-2 rounded-full border border-violet-200/55 bg-white/65 p-2 ps-5 shadow-[0_18px_55px_rgba(41,38,46,0.18)] backdrop-blur-2xl dark:border-violet-400/15 dark:bg-zinc-900/65">
            <label for="moment-comment" class="sr-only">{{ __('Leave a comment') }}</label>
            <input id="moment-comment" wire:model="commentBody" type="text" maxlength="2000" placeholder="{{ __('Leave a comment…') }}" class="min-w-0 flex-1 border-0 bg-transparent py-2 text-sm text-zinc-900 outline-none placeholder:text-zinc-400 focus:ring-0 dark:text-white">
            <button type="submit" class="inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-violet-600 text-white shadow-sm transition hover:bg-violet-700 disabled:opacity-50 dark:bg-violet-500 dark:hover:bg-violet-400" wire:loading.attr="disabled" wire:target="addComment" aria-label="{{ __('Send comment') }}">
                <flux:icon.paper-airplane class="size-5" />
            </button>
        </div>
        <flux:error name="commentBody" class="mx-5 mt-2 rounded-full bg-white/95 px-4 py-2 shadow-sm dark:bg-zinc-900/95" />
    </form>

    <flux:modal name="edit-moment-detail" focusable class="max-w-lg">
        <form wire:submit="updateMoment" class="space-y-5">
            <div>
                <flux:heading size="xl">{{ __('Edit post') }}</flux:heading>
                <flux:subheading>{{ __('Refine the post text or its intensity.') }}</flux:subheading>
            </div>
            <flux:input wire:model="editIntensity" type="number" min="1" max="10" :label="__('Intensity')" />
            <flux:textarea wire:model="editBody" :label="__('Post text')" rows="6" maxlength="5000" />
            <flux:error name="editIntensity" />
            <flux:error name="editBody" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save changes') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
