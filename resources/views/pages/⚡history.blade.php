<?php

use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskKind;
use App\Models\PromptRound;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('History')] class extends Component
{
    /** @return Collection<int, PromptRound> */
    #[Computed]
    public function rounds(): Collection
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);
        $relationship = $user->relationships()->first();

        if (! $relationship) {
            return new Collection;
        }

        return $relationship->rounds()
            ->where('status', PromptRoundStatus::Revealed)
            ->with(['tasks.assignee', 'tasks.questionResponse', 'tasks.photos', 'tasks.photoSelection.photo.task.assignee'])
            ->latest('revealed_at')
            ->get();
    }
}; ?>

<div class="home-shell mx-auto flex w-full max-w-3xl flex-col gap-8 pb-6 sm:pt-5">
    <header class="px-1">
        <div class="mb-3 flex items-center gap-2.5 text-xs font-semibold uppercase tracking-[0.16em] text-violet-600 dark:text-violet-300">
            <span class="size-1.5 rounded-full bg-violet-500 shadow-[0_0_0_4px_rgba(139,92,246,0.12)]"></span>
            <span>{{ __('Looking back') }}</span>
        </div>
        <h1 class="text-[2.15rem] font-semibold leading-none tracking-[-0.04em] text-zinc-950 sm:text-5xl dark:text-white">
            {{ __('History') }}
        </h1>
        <p class="mt-3 max-w-xl text-base leading-7 text-zinc-500 sm:text-lg dark:text-zinc-400">
            {{ __('The questions, answers, and favorites you have revealed together.') }}
        </p>
    </header>

    @if ($this->rounds->isNotEmpty())
        <div class="flex items-center justify-between px-1">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500">{{ __('Revealed together') }}</p>
            <span class="text-xs text-zinc-400 dark:text-zinc-500">
                {{ trans_choice(':count result|:count results', $this->rounds->count(), ['count' => $this->rounds->count()]) }}
            </span>
        </div>

        <div class="space-y-5" data-page-stagger>
            @foreach ($this->rounds as $round)
                @php
                    $isPhotoRound = in_array($round->kind, [PromptRoundKind::PhotoPicker, PromptRoundKind::PhotoRequest], true);
                    $isSharedQuestion = $round->kind === PromptRoundKind::SharedQuestion;
                    $kindLabel = match ($round->kind) {
                        PromptRoundKind::UniqueQuestions => __('Different questions'),
                        PromptRoundKind::PhotoPicker => __('Photo favorite'),
                        PromptRoundKind::PhotoRequest => __('Photo request'),
                        default => __('Shared question'),
                    };
                    $kindIcon = $isPhotoRound ? 'photo' : 'chat-bubble-left-right';
                @endphp

                <article class="prompt-surface" wire:key="history-round-{{ $round->id }}">
                    <header class="flex items-start justify-between gap-4 border-b border-zinc-200/70 p-5 sm:p-6 dark:border-white/8">
                        <div class="flex min-w-0 items-center gap-3.5">
                            <div class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-violet-50 text-violet-600 ring-1 ring-violet-100 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/15">
                                <flux:icon :name="$kindIcon" class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-base font-semibold tracking-[-0.015em] text-zinc-950 dark:text-white">{{ $kindLabel }}</h2>
                                    @if ($round->prompt_source === 'ai')
                                        <flux:badge size="sm" color="violet" icon="sparkles">{{ __('Made for you') }}</flux:badge>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ __('A round you completed together') }}</p>
                            </div>
                        </div>

                        <time datetime="{{ $round->revealed_at?->toDateString() }}" class="shrink-0 rounded-full bg-zinc-100/80 px-3 py-1.5 text-xs font-medium text-zinc-500 ring-1 ring-zinc-200/70 dark:bg-white/5 dark:text-zinc-400 dark:ring-white/8">
                            {{ $round->revealed_at?->format('M j, Y') }}
                        </time>
                    </header>

                    @if ($isPhotoRound)
                        @php
                            $pickerTasks = $round->tasks
                                ->where('kind', PromptTaskKind::PhotoPick)
                                ->filter(fn ($task) => $task->photoSelection?->photo);
                            $requestTask = $round->tasks->firstWhere('kind', PromptTaskKind::Question);
                        @endphp

                        <div class="p-5 sm:p-6">
                            @if ($requestTask?->questionResponse)
                                <div class="rounded-2xl bg-violet-50/80 p-4 ring-1 ring-violet-100 dark:bg-violet-500/10 dark:ring-violet-400/15">
                                    <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.14em] text-violet-600 dark:text-violet-300">{{ __('The request') }}</p>
                                    <p class="mt-2 text-[0.9375rem] leading-6 text-violet-950 dark:text-violet-50">{{ $requestTask->questionResponse->answer }}</p>
                                    @if ($requestTask->photos->isNotEmpty())
                                        <div class="mt-4 grid grid-cols-3 gap-2">
                                            @foreach ($requestTask->photos as $photo)
                                                <img src="{{ route('round-photos.show', $photo) }}" alt="{{ __('Photo shared with the request') }}" class="aspect-square w-full rounded-xl object-cover">
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif

                            <div class="grid gap-4 {{ $pickerTasks->count() > 1 ? 'sm:grid-cols-2' : '' }} {{ $requestTask?->questionResponse ? 'mt-5' : '' }}">
                                @foreach ($pickerTasks as $pickerTask)
                                    @php($favorite = $pickerTask->photoSelection->photo)
                                    <figure class="group relative min-h-72 overflow-hidden rounded-[1.4rem] bg-zinc-200 shadow-sm ring-1 ring-black/5 dark:bg-zinc-800 dark:ring-white/10">
                                        <img
                                            src="{{ route('round-photos.show', $favorite) }}"
                                            alt="{{ __('The selected favorite photo') }}"
                                            class="absolute inset-0 size-full object-cover transition duration-700 ease-out group-hover:scale-[1.025]"
                                            loading="lazy"
                                        >
                                        <div class="absolute inset-0 bg-gradient-to-b from-black/5 via-transparent to-black/80"></div>
                                        <figcaption class="absolute inset-x-0 bottom-0 p-5 text-white">
                                            <p class="text-base font-semibold">{{ __('This one was the favorite') }}</p>
                                            <p class="mt-1 text-sm leading-5 text-white/75">
                                                {{ __(':picker chose it from :uploader’s three photos.', ['picker' => $pickerTask->assignee->name, 'uploader' => $favorite->task->assignee->name]) }}
                                            </p>
                                        </figcaption>
                                    </figure>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="p-5 sm:p-6">
                            @if ($isSharedQuestion)
                                <div class="rounded-2xl bg-violet-50/75 px-5 py-4 ring-1 ring-violet-100 dark:bg-violet-500/10 dark:ring-violet-400/15">
                                    <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.14em] text-violet-600 dark:text-violet-300">{{ __('The question') }}</p>
                                    <p class="mt-2 text-base font-medium leading-6 tracking-[-0.01em] text-zinc-900 dark:text-white">{{ $round->tasks->first()?->prompt }}</p>
                                </div>
                            @endif

                            <div class="grid gap-3 {{ $isSharedQuestion ? 'mt-4 sm:grid-cols-2' : 'sm:grid-cols-2' }}">
                                @foreach ($round->tasks as $task)
                                    <section class="rounded-2xl bg-zinc-50/75 p-4 ring-1 ring-zinc-200/70 dark:bg-white/[0.035] dark:ring-white/8">
                                        <div class="flex items-center gap-2.5">
                                            <flux:avatar circle size="xs" :name="$task->assignee->name" :initials="$task->assignee->initials()" />
                                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $task->assignee->name }}</span>
                                        </div>

                                        @if (! $isSharedQuestion)
                                            <p class="mt-4 text-sm leading-5 text-zinc-500 dark:text-zinc-400">{{ $task->prompt }}</p>
                                        @endif

                                        <p class="mt-3 whitespace-pre-line text-[0.9375rem] leading-6 text-zinc-800 dark:text-zinc-100">{{ $task->questionResponse?->answer }}</p>
                                        @if ($task->photos->isNotEmpty())
                                            <div class="mt-4 grid grid-cols-3 gap-2">
                                                @foreach ($task->photos as $photo)
                                                    <img src="{{ route('round-photos.show', $photo) }}" alt="{{ __('Photo shared by :name', ['name' => $task->assignee->name]) }}" class="aspect-square w-full rounded-xl object-cover">
                                                @endforeach
                                            </div>
                                        @endif
                                    </section>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @else
        <div class="prompt-surface-muted flex min-h-72 items-center justify-center p-8 text-center">
            <div class="max-w-sm">
                <div class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-500 ring-1 ring-violet-100 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/15">
                    <flux:icon.clock class="size-5" />
                </div>
                <flux:heading class="mt-4">{{ __('Your story starts here') }}</flux:heading>
                <flux:text class="mt-1 leading-6">{{ __('Completed prompts will settle into this space after you reveal them together.') }}</flux:text>
            </div>
        </div>
    @endif
</div>
