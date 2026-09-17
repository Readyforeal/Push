<?php

use App\Concerns\PreparesPhotoUploads;
use App\Enums\PromptRoundKind;
use App\Enums\PromptRoundOrigin;
use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskKind;
use App\Enums\PromptTaskStatus;
use App\Models\PromptRound;
use App\Models\PromptRoundTask;
use App\Models\Relationship;
use App\Models\RoundPhoto;
use App\Models\User;
use App\Services\PhotoStorage;
use App\Services\PromptRoundWorkflow;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component
{
    use PreparesPhotoUploads, WithFileUploads;

    public string $answer = '';

    public ?int $roundId = null;

    public bool $summary = false;

    /** @var array<int, TemporaryUploadedFile> */
    public array $photos = [];

    /** @var array<int, TemporaryUploadedFile> */
    public array $answerPhotos = [];

    public ?int $selectedPhotoId = null;

    public function updatedPhotos(PhotoStorage $photoStorage): void
    {
        $this->preparePhotoUploads('photos', $photoStorage);
    }

    public function updatedAnswerPhotos(PhotoStorage $photoStorage): void
    {
        $this->preparePhotoUploads('answerPhotos', $photoStorage);
    }

    public function mount(?int $roundId = null, bool $summary = false): void
    {
        $this->roundId = $roundId;
        $this->summary = $summary;
        $this->answer = $this->task?->questionResponse?->answer ?? '';
    }

    public function saveDraft(PromptRoundWorkflow $workflow): void
    {
        $this->validate(['answer' => ['nullable', 'string', 'max:5000']]);
        $task = $this->task;

        if (! $task) {
            return;
        }

        try {
            $workflow->saveQuestionDraft($task, $this->user(), $this->answer);
        } catch (DomainException $exception) {
            $this->addError('answer', $exception->getMessage());

            return;
        }

        unset($this->task);
        Flux::toast(variant: 'success', text: __('Draft saved.'));
    }

    public function submitAnswer(PromptRoundWorkflow $workflow, PhotoStorage $photoStorage): void
    {
        if ($this->photoPreparationFailed('answerPhotos')) {
            $this->addError('answerPhotos', __('Choose the failed photo again before submitting your answer.'));

            return;
        }

        $task = $this->task;

        if (! $task) {
            return;
        }

        $requiresPhotos = (bool) ($task->payload['requires_photos'] ?? false);
        $rules = ['answer' => ['required', 'string', 'max:5000']];

        if ($requiresPhotos) {
            $rules['answerPhotos'] = ['required', 'array', 'min:1', 'max:3'];
            $rules['answerPhotos.*'] = ['required', 'file', 'extensions:jpg,jpeg,png,gif,webp,tif,tiff,dng,heic,heif', 'max:512000'];
        }

        $this->validate($rules, [
            'answerPhotos.required' => __('Add at least one photo for this prompt.'),
            'answerPhotos.min' => __('Add at least one photo for this prompt.'),
            'answerPhotos.max' => __('Choose no more than three photos.'),
        ]);

        $storedPhotos = [];
        $mediaDisk = (string) config('filesystems.media_disk', 'homelab_cloud');

        try {
            foreach ($this->answerPhotos as $position => $photo) {
                $stored = $photoStorage->store($photo, "rounds/{$task->prompt_round_id}/{$task->id}", $mediaDisk);
                $storedPhotos[] = $task->photos()->create([
                    'disk' => $mediaDisk,
                    'path' => $stored['path'],
                    'original_name' => $photo->getClientOriginalName(),
                    'mime_type' => $stored['mime_type'],
                    'size' => $stored['size'],
                    'position' => $position + 1,
                ]);
            }

            $workflow->submitQuestion($task, $this->user(), $this->answer);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                report($exception);
            }

            foreach ($storedPhotos as $storedPhoto) {
                Storage::disk($storedPhoto->disk)->delete($storedPhoto->path);
                $storedPhoto->delete();
            }

            $this->addError('answer', $exception instanceof DomainException
                ? $exception->getMessage()
                : __('Your answer could not be submitted. Please try again.'));

            return;
        }

        $this->reset('answerPhotos');
        unset($this->round, $this->task, $this->latestResult);
        Flux::toast(variant: 'success', text: __('Answer submitted.'));
    }

    public function submitPhotos(PromptRoundWorkflow $workflow, PhotoStorage $photoStorage): void
    {
        if ($this->photoPreparationFailed('photos')) {
            $this->addError('photos', __('Choose the failed photo again before sending your photos.'));

            return;
        }

        $this->validate([
            'photos' => ['required', 'array', 'size:3'],
            'photos.*' => ['required', 'file', 'extensions:jpg,jpeg,png,gif,webp,tif,tiff,dng,heic,heif', 'max:512000'],
        ], [
            'photos.size' => __('Please choose exactly three photos.'),
            'photos.*.extensions' => __('Choose a JPEG, PNG, HEIC, TIFF, WebP, GIF, or DNG photo.'),
            'photos.*.max' => __('Each photo must be 500 MB or smaller.'),
        ]);

        $task = $this->task;

        if (! $task || $task->kind !== PromptTaskKind::PhotoUpload) {
            return;
        }

        $storedPhotos = [];
        $mediaDisk = (string) config('filesystems.media_disk', 'homelab_cloud');

        try {
            foreach ($this->photos as $position => $photo) {
                $stored = $photoStorage->store($photo, "rounds/{$task->prompt_round_id}/{$task->id}", $mediaDisk);

                $storedPhotos[] = $task->photos()->create([
                    'disk' => $mediaDisk,
                    'path' => $stored['path'],
                    'original_name' => $photo->getClientOriginalName(),
                    'mime_type' => $stored['mime_type'],
                    'size' => $stored['size'],
                    'position' => $position + 1,
                ]);
            }

            $workflow->submitPhotos($task, $this->user());
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                report($exception);
            }

            foreach ($storedPhotos as $storedPhoto) {
                Storage::disk($storedPhoto->disk)->delete($storedPhoto->path);
                $storedPhoto->delete();
            }

            $this->addError('photos', $exception instanceof DomainException
                ? $exception->getMessage()
                : __('The photos could not be submitted. Please try again.'));

            return;
        }

        $this->reset('photos');
        unset($this->round, $this->task, $this->latestResult);
        Flux::toast(variant: 'success', text: __('Photos sent to your partner.'));
    }

    public function submitSelection(PromptRoundWorkflow $workflow): void
    {
        $this->validate(['selectedPhotoId' => ['required', 'integer']]);
        $task = $this->task;

        if (! $task || $task->kind !== PromptTaskKind::PhotoPick) {
            return;
        }

        $photo = RoundPhoto::query()->findOrFail($this->selectedPhotoId);
        Gate::authorize('view', $photo);

        try {
            $workflow->selectPhoto($task, $this->user(), $photo);
        } catch (DomainException $exception) {
            $this->addError('selectedPhotoId', $exception->getMessage());

            return;
        }

        $backgroundUrl = $this->user()->fresh()->appBackgroundUrl();

        $this->dispatch(
            'app-background-updated',
            url: $backgroundUrl,
        );
        unset($this->round, $this->task, $this->latestResult);
        Flux::toast(variant: 'success', text: __('Favorite selected.'));
    }

    #[Computed]
    public function relationship(): ?Relationship
    {
        return $this->user()->relationships()->first();
    }

    #[Computed]
    public function round(): ?PromptRound
    {
        return $this->relationship?->rounds()
            ->where('status', PromptRoundStatus::Active)
            ->when($this->roundId, fn ($query) => $query->whereKey($this->roundId))
            ->when(! $this->roundId, fn ($query) => $query->where('origin', PromptRoundOrigin::Scheduled))
            ->latest('available_at')
            ->first();
    }

    #[Computed]
    public function task(): ?PromptRoundTask
    {
        return $this->round?->tasks()
            ->with([
                'questionResponse',
                'dependency.assignee',
                'dependency.questionResponse',
                'dependency.photos',
                'dependency.dependency.questionResponse',
                'photoSelection.photo',
            ])
            ->where('user_id', $this->user()->id)
            ->reorder()
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [
                PromptTaskStatus::Active->value,
                PromptTaskStatus::Locked->value,
            ])
            ->orderBy('position')
            ->first();
    }

    #[Computed]
    public function latestResult(): ?PromptRound
    {
        return $this->relationship?->rounds()
            ->where('status', PromptRoundStatus::Revealed)
            ->when($this->roundId, fn ($query) => $query->whereKey($this->roundId))
            ->when(! $this->roundId, fn ($query) => $query->where('origin', PromptRoundOrigin::Scheduled))
            ->with(['tasks.assignee', 'tasks.questionResponse', 'tasks.photos', 'tasks.photoSelection.photo.task.assignee'])
            ->latest('revealed_at')
            ->first();
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}; ?>

<div class="transition-opacity duration-300" wire:loading.class="opacity-60">
    @if ($summary)
        @php
            $summaryRound = $this->round ?? $this->latestResult;
        @endphp
        @if ($summaryRound)
            <a href="{{ route('prompts.show', $summaryRound) }}" wire:navigate.hover class="prompt-surface group block p-6 transition duration-200 hover:-translate-y-0.5 hover:shadow-xl sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge color="violet" size="sm">
                                {{ $summaryRound->origin === PromptRoundOrigin::Extracurricular ? __('Extracurricular') : __('Today’s prompt') }}
                            </flux:badge>
                            @if ($summaryRound->status === PromptRoundStatus::Revealed)
                                <flux:badge color="emerald" size="sm">{{ __('Completed') }}</flux:badge>
                            @endif
                        </div>
                        <h2 class="mt-4 text-xl font-semibold leading-snug tracking-[-0.025em] text-zinc-950 sm:text-2xl dark:text-white">
                            {{ $this->task?->prompt ?? $summaryRound->tasks->first()?->prompt ?? __('Your prompt is ready') }}
                        </h2>
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $summaryRound->status === PromptRoundStatus::Revealed ? __('Open your shared result.') : __('Open the prompt to answer or continue.') }}
                        </p>
                    </div>
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-violet-600 text-white shadow-sm transition group-hover:translate-x-0.5 dark:bg-violet-500">
                        <flux:icon.arrow-right class="size-5" />
                    </span>
                </div>
            </a>
        @else
            <section class="prompt-surface-muted p-6 text-center">
                <flux:heading size="lg">{{ __('Ready for your first prompt') }}</flux:heading>
                <flux:text class="mt-1">{{ __('It will appear here when it’s time.') }}</flux:text>
            </section>
        @endif
    @elseif ($this->round && $this->task?->kind === PromptTaskKind::Question)
        @if ($this->task->status === PromptTaskStatus::Active)
            <section class="prompt-surface relative p-6 sm:p-8">
                <div class="pointer-events-none absolute -right-20 -top-24 size-56 rounded-full bg-violet-400/10 blur-3xl dark:bg-violet-400/8"></div>

                <div class="relative flex items-center justify-between gap-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge color="violet" size="sm">
                            @if ($this->round->kind === PromptRoundKind::PhotoRequest)
                                {{ __('Photo request') }}
                            @else
                                {{ $this->round->kind === PromptRoundKind::UniqueQuestions ? __('Just for you') : __('Shared question') }}
                            @endif
                        </flux:badge>
                        @if ($this->round->prompt_source === 'ai')
                            <flux:badge color="zinc" size="sm" icon="sparkles">{{ __('Made for you') }}</flux:badge>
                        @endif
                    </div>
                    <span class="prompt-kicker">{{ $this->round->available_at->format('M j') }}</span>
                </div>

                <h2 class="relative mt-8 max-w-2xl text-2xl font-semibold leading-[1.25] tracking-[-0.025em] text-zinc-950 sm:text-[2rem] dark:text-white">
                    {{ $this->task->prompt }}
                </h2>

                <form wire:submit="submitAnswer" class="relative mt-8 space-y-5">
                    <flux:textarea
                        wire:model="answer"
                        :label="$this->round->kind === PromptRoundKind::PhotoRequest ? __('What you’d like to see') : __('Your answer')"
                        :placeholder="$this->round->kind === PromptRoundKind::PhotoRequest ? __('Describe the photo you have in mind…') : __('Take your time…')"
                        rows="6"
                        maxlength="5000"
                        class="[&_textarea]:rounded-2xl [&_textarea]:bg-zinc-50/80 [&_textarea]:shadow-inner dark:[&_textarea]:bg-black/15"
                    />

                    @if ($this->task->payload['requires_photos'] ?? false)
                        <div
                            class="space-y-3"
                            x-data="photoUploadPreview()"
                            x-on:livewire-upload-start="startUpload()"
                            x-on:livewire-upload-progress="updateProgress($event)"
                            x-on:livewire-upload-finish="finishUpload()"
                            x-on:livewire-upload-error="finishUpload()"
                        >
                            <label class="group flex cursor-pointer items-center gap-4 rounded-2xl border border-dashed border-violet-300 bg-violet-50/55 p-4 transition hover:bg-violet-50 dark:border-violet-400/30 dark:bg-violet-500/8 dark:hover:bg-violet-500/12">
                                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white text-violet-600 shadow-sm dark:bg-white/8 dark:text-violet-300">
                                    <flux:icon.photo class="size-5" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Add photos') }}</span>
                                    <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">{{ __('This prompt requires 1–3 photos with your answer.') }}</span>
                                </span>
                                <input wire:model="answerPhotos" x-on:change="selectFiles($event)" type="file" accept="image/*,.dng,.heic,.heif,.tif,.tiff" multiple class="sr-only">
                            </label>

                            <div x-show="uploading" x-cloak class="space-y-2">
                                <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                                    <span x-text="progress < 100 ? `Uploading ${progress}%` : 'Finishing photos…'"></span>
                                    <span x-show="progress < 100" x-text="`${progress}%`"></span>
                                </div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-zinc-200/80 dark:bg-white/10">
                                    <div class="h-full rounded-full bg-violet-500 transition-[width] duration-200" :style="`width: ${progress}%`"></div>
                                </div>
                            </div>

                            <div x-show="previews.length > 0" x-cloak class="grid grid-cols-3 gap-3">
                                <template x-for="preview in previews" :key="preview.url">
                                    <img :src="preview.url" :alt="preview.name" class="aspect-square w-full rounded-2xl object-cover shadow-sm ring-1 ring-black/5">
                                </template>
                            </div>

                            @if (count($answerPhotos) > 0)
                                <div x-show="previews.length === 0" class="grid grid-cols-3 gap-3">
                                    @foreach ($answerPhotos as $photo)
                                        <img src="{{ $photo->temporaryUrl() }}" alt="{{ __('Selected photo preview') }}" class="aspect-square w-full rounded-2xl object-cover shadow-sm ring-1 ring-black/5">
                                    @endforeach
                                </div>
                            @endif

                            <flux:error name="answerPhotos" />
                        </div>
                    @endif

                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <flux:button type="button" variant="ghost" wire:click="saveDraft" class="sm:px-5">
                            {{ __('Save draft') }}
                        </flux:button>
                        <flux:button type="submit" variant="primary" icon="paper-airplane" class="sm:px-5" wire:loading.attr="disabled" wire:target="answerPhotos,submitAnswer">
                            <span wire:loading.remove wire:target="answerPhotos,submitAnswer">{{ $this->round->kind === PromptRoundKind::PhotoRequest ? __('Send request') : __('Submit answer') }}</span>
                            <span wire:loading wire:target="answerPhotos,submitAnswer">{{ __('Working…') }}</span>
                        </flux:button>
                    </div>

                    <div class="flex items-start gap-2 border-t border-zinc-100 pt-4 text-xs leading-5 text-zinc-400 dark:border-white/8 dark:text-zinc-500">
                        @if ($this->round->kind === PromptRoundKind::PhotoRequest)
                            <flux:icon.camera class="mt-0.5 size-3.5 shrink-0" />
                            <span>{{ __('Your partner will see this request so they know what to photograph.') }}</span>
                        @else
                            <flux:icon.lock-closed class="mt-0.5 size-3.5 shrink-0" />
                            <span>{{ __('Your partner will not see your answer until both of you submit.') }}</span>
                        @endif
                    </div>
                </form>
            </section>
        @else
            <section class="prompt-surface flex min-h-72 items-center justify-center p-8 text-center">
                <div class="max-w-sm">
                    <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 ring-8 ring-emerald-50/60 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/5">
                        <flux:icon.check class="size-6" />
                    </div>
                    <flux:heading size="xl" class="mt-6 tracking-tight">{{ __('Answer submitted') }}</flux:heading>
                    <flux:text class="mt-2 leading-6">{{ __('Waiting for your partner. Your answers will be revealed together.') }}</flux:text>
                </div>
            </section>
        @endif
    @elseif ($this->round && $this->task?->kind === PromptTaskKind::PhotoUpload)
        @if ($this->task->status === PromptTaskStatus::Active)
            <section class="prompt-surface p-6 sm:p-8">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge color="rose" size="sm">
                            {{ $this->round->kind === PromptRoundKind::PhotoRequest ? __('Photo request') : __('Photo round') }}
                        </flux:badge>
                        @if ($this->round->prompt_source === 'ai')
                            <flux:badge color="zinc" size="sm" icon="sparkles">{{ __('Made for you') }}</flux:badge>
                        @endif
                    </div>
                    <span class="prompt-kicker">{{ $this->round->available_at->format('M j') }}</span>
                </div>

                @if ($this->round->kind === PromptRoundKind::PhotoRequest)
                    <flux:text class="mt-8 text-sm">{{ $this->task->prompt }}</flux:text>
                    <div class="app-glass-card mt-3 rounded-2xl bg-violet-50 p-5 backdrop-blur-xl ring-1 ring-violet-100 dark:bg-violet-500/10 dark:ring-violet-400/15">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-violet-500 dark:text-violet-300">{{ __('They’d like to see') }}</p>
                        <p class="mt-2 text-lg font-medium leading-7 text-zinc-900 dark:text-white">{{ $this->task->dependency?->questionResponse?->answer }}</p>
                    </div>
                @else
                    <flux:heading size="xl" class="mt-8 leading-snug tracking-tight">{{ $this->task->prompt }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Your partner will choose the one they love most.') }}</flux:text>
                @endif

                <form
                    wire:submit="submitPhotos"
                    class="mt-6 space-y-5"
                    x-data="photoUploadPreview()"
                    x-on:livewire-upload-start="startUpload()"
                    x-on:livewire-upload-progress="updateProgress($event)"
                    x-on:livewire-upload-finish="finishUpload()"
                    x-on:livewire-upload-error="finishUpload()"
                >
                    <label class="group flex cursor-pointer flex-col items-center justify-center rounded-3xl border border-dashed border-zinc-300 bg-zinc-50/70 px-6 py-12 text-center transition duration-200 hover:border-rose-300 hover:bg-rose-50/50 dark:border-white/15 dark:bg-black/10 dark:hover:border-rose-400/50 dark:hover:bg-rose-500/5">
                        <span class="flex size-12 items-center justify-center rounded-full bg-white text-zinc-400 shadow-sm ring-1 ring-zinc-200 transition group-hover:scale-105 group-hover:text-rose-500 dark:bg-white/8 dark:ring-white/10">
                            <flux:icon.photo class="size-6" />
                        </span>
                        <span class="mt-3 font-medium text-zinc-900 dark:text-white">{{ __('Choose three photos') }}</span>
                        <span class="mt-1 text-sm text-zinc-500">{{ __('RAW, HEIC, and everyday photos are prepared as large JPEGs') }}</span>
                        <input wire:model="photos" x-on:change="selectFiles($event)" type="file" accept="image/*,.dng,.heic,.heif,.tif,.tiff" multiple class="sr-only">
                    </label>

                    <div x-show="uploading" x-cloak class="space-y-2">
                        <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                            <span x-text="progress < 100 ? `Uploading ${progress}%` : 'Finishing photos…'"></span>
                            <span x-show="progress < 100" x-text="`${progress}%`"></span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-zinc-200/80 dark:bg-white/10">
                            <div class="h-full rounded-full bg-violet-500 transition-[width] duration-200" :style="`width: ${progress}%`"></div>
                        </div>
                    </div>

                    <div x-show="previews.length > 0" x-cloak class="grid grid-cols-3 gap-3">
                        <template x-for="preview in previews" :key="preview.url">
                            <img :src="preview.url" :alt="preview.name" class="aspect-square w-full rounded-2xl object-cover shadow-sm ring-1 ring-black/5">
                        </template>
                    </div>

                    @if (count($photos) > 0)
                        <div x-show="previews.length === 0" class="grid grid-cols-3 gap-3">
                            @foreach ($photos as $photo)
                                <img src="{{ $photo->temporaryUrl() }}" alt="{{ __('Selected photo preview') }}" class="aspect-square w-full rounded-2xl object-cover shadow-sm ring-1 ring-black/5">
                            @endforeach
                        </div>
                    @endif

                    <flux:error name="photos" />
                    @foreach ($errors->get('photos.*') as $photoErrors)
                        @foreach ($photoErrors as $photoError)
                            <p class="text-sm text-red-600 dark:text-red-400">{{ $photoError }}</p>
                        @endforeach
                    @endforeach

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" wire:target="photos,submitPhotos">
                            <span wire:loading.remove wire:target="photos,submitPhotos">{{ __('Send these photos') }}</span>
                            <span wire:loading wire:target="photos,submitPhotos">{{ __('Working…') }}</span>
                        </flux:button>
                    </div>
                </form>
            </section>
        @elseif ($this->task->status === PromptTaskStatus::Locked)
            <section class="prompt-surface-muted flex min-h-72 items-center justify-center p-8 text-center">
                <div class="max-w-sm">
                    <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-violet-50 text-violet-500 ring-8 ring-violet-50/60 dark:bg-violet-500/15 dark:text-violet-300 dark:ring-violet-500/5">
                        <flux:icon.chat-bubble-left-right class="size-6" />
                    </span>
                    <flux:heading size="lg" class="mt-6 tracking-tight">{{ __('Waiting for your partner’s request') }}</flux:heading>
                    <flux:text class="mt-2 leading-6">{{ __('They’re deciding what they would love for you to photograph. We’ll notify you when it’s ready.') }}</flux:text>
                </div>
            </section>
        @else
            <section class="prompt-surface flex min-h-72 items-center justify-center p-8 text-center">
                <div class="max-w-sm">
                    <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 ring-8 ring-emerald-50/60 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/5">
                        <flux:icon.check class="size-6" />
                    </div>
                    <flux:heading size="xl" class="mt-6 tracking-tight">{{ __('Photos sent') }}</flux:heading>
                    <flux:text class="mt-2 leading-6">{{ __('Waiting for your partner to pick their favorite.') }}</flux:text>
                </div>
            </section>
        @endif
    @elseif ($this->round && $this->task?->kind === PromptTaskKind::PhotoPick)
        @if ($this->task->status === PromptTaskStatus::Locked)
            <section class="prompt-surface-muted flex min-h-72 items-center justify-center p-8 text-center">
                <div class="max-w-sm">
                    <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-white text-zinc-400 shadow-sm ring-1 ring-zinc-200 dark:bg-white/8 dark:ring-white/10">
                        <flux:icon.photo class="size-6" />
                    </span>
                    <flux:heading size="lg" class="mt-6 tracking-tight">
                        {{ $this->round->kind === PromptRoundKind::PhotoRequest ? __('Your partner is taking photos') : __('Waiting for your partner’s photos') }}
                    </flux:heading>
                    <flux:text class="mt-2 leading-6">{{ __('We’ll notify you when they are ready to view.') }}</flux:text>
                </div>
            </section>
        @else
            <section class="prompt-surface p-6 sm:p-8">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge color="rose" size="sm">
                        {{ $this->round->kind === PromptRoundKind::PhotoRequest ? __('Your request is ready') : __('Pick a favorite') }}
                    </flux:badge>
                    @if ($this->round->prompt_source === 'ai')
                        <flux:badge color="zinc" size="sm" icon="sparkles">{{ __('Made for you') }}</flux:badge>
                    @endif
                </div>
                <flux:heading size="xl" class="mt-6 leading-snug tracking-tight">{{ $this->task->prompt }}</flux:heading>
                @if ($this->round->kind === PromptRoundKind::PhotoRequest && $this->task->dependency?->dependency?->questionResponse)
                    <div class="app-glass-card mt-4 rounded-2xl bg-violet-50 px-4 py-3 text-sm text-violet-800 backdrop-blur-xl dark:bg-violet-500/10 dark:text-violet-200">
                        {{ $this->task->dependency->dependency->questionResponse->answer }}
                    </div>
                @endif
                <flux:text class="mt-2">{{ __('Tap the photo that speaks to you most.') }}</flux:text>

                <form wire:submit="submitSelection" class="mt-7 space-y-6">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        @foreach ($this->task->dependency->photos as $photo)
                            <label class="group relative cursor-pointer overflow-hidden rounded-3xl shadow-sm ring-offset-3 ring-offset-white transition duration-200 hover:-translate-y-0.5 hover:shadow-lg dark:ring-offset-zinc-900 {{ $selectedPhotoId === $photo->id ? 'ring-3 ring-rose-500' : 'ring-1 ring-black/5 dark:ring-white/10' }}">
                                <input wire:model.live="selectedPhotoId" type="radio" value="{{ $photo->id }}" class="sr-only">
                                <img src="{{ route('round-photos.show', $photo) }}" alt="{{ __('Photo option :number', ['number' => $photo->position]) }}" class="aspect-square w-full object-cover transition duration-500 group-hover:scale-[1.03]">
                                @if ($selectedPhotoId === $photo->id)
                                    <span class="absolute right-3 top-3 flex size-8 items-center justify-center rounded-full bg-rose-500 text-white shadow-lg ring-2 ring-white/80">
                                        <flux:icon.check class="size-5" />
                                    </span>
                                @endif
                            </label>
                        @endforeach
                    </div>

                    <flux:error name="selectedPhotoId" />

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" icon="heart" :disabled="$selectedPhotoId === null">
                            {{ __('This is my favorite') }}
                        </flux:button>
                    </div>
                </form>
            </section>
        @endif
    @elseif ($this->round)
        <section class="prompt-surface-muted flex min-h-72 items-center justify-center p-8 text-center">
            <div class="max-w-sm">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-violet-50 text-violet-500 ring-8 ring-violet-50/60 dark:bg-violet-500/15 dark:text-violet-300 dark:ring-violet-500/5">
                    <flux:icon.sparkles class="size-6" />
                </span>
                <flux:heading size="lg" class="mt-6 tracking-tight">{{ __('Your partner is preparing this round') }}</flux:heading>
                <flux:text class="mt-2 leading-6">{{ __('We’ll let you know when it is your turn.') }}</flux:text>
            </div>
        </section>
    @elseif ($this->latestResult)
        <section class="prompt-surface p-6 sm:p-8">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:badge color="emerald" size="sm">
                        @if ($this->latestResult->kind === PromptRoundKind::UniqueQuestions)
                            {{ __('Your questions, revealed') }}
                        @elseif ($this->latestResult->kind === PromptRoundKind::PhotoPicker)
                            {{ __('Your favorites were picked') }}
                        @elseif ($this->latestResult->kind === PromptRoundKind::PhotoRequest)
                            {{ __('Request fulfilled') }}
                        @else
                            {{ __('Revealed') }}
                        @endif
                    </flux:badge>
                    <flux:heading size="xl" class="mt-4 tracking-tight">{{ __('Your round result') }}</flux:heading>
                </div>
                <flux:button :href="route('history')" wire:navigate.hover variant="ghost" size="sm">{{ __('History') }}</flux:button>
            </div>

            @if (in_array($this->latestResult->kind, [PromptRoundKind::PhotoPicker, PromptRoundKind::PhotoRequest], true))
                @php
                    $pickerTasks = $this->latestResult->tasks
                        ->where('kind', PromptTaskKind::PhotoPick)
                        ->filter(fn ($task) => $task->photoSelection?->photo);
                    $requestTask = $this->latestResult->tasks->firstWhere('kind', PromptTaskKind::Question);
                @endphp

                @if ($requestTask?->questionResponse)
                    <div class="app-glass-card mt-6 rounded-2xl bg-violet-50 p-4 text-violet-900 backdrop-blur-xl dark:bg-violet-500/10 dark:text-violet-100">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-violet-500 dark:text-violet-300">{{ __('The request') }}</p>
                        <p class="mt-2">{{ $requestTask->questionResponse->answer }}</p>
                        @if ($requestTask->photos->isNotEmpty())
                            <div class="mt-4 grid grid-cols-3 gap-2">
                                @foreach ($requestTask->photos as $photo)
                                    <img src="{{ route('round-photos.show', $photo) }}" alt="{{ __('Photo shared with the request') }}" class="aspect-square w-full rounded-xl object-cover">
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                <div class="mt-6 grid gap-4 {{ $pickerTasks->count() > 1 ? 'sm:grid-cols-2' : '' }}">
                    @foreach ($pickerTasks as $pickerTask)
                        @php($favorite = $pickerTask->photoSelection->photo)
                        <div class="app-glass-card overflow-hidden rounded-3xl bg-zinc-50 backdrop-blur-xl ring-1 ring-black/5 dark:bg-zinc-800 dark:ring-white/10">
                            <img src="{{ route('round-photos.show', $favorite) }}" alt="{{ __('The selected favorite photo') }}" class="aspect-[4/3] w-full object-cover">
                            <div class="p-5">
                                <flux:heading>{{ __('This one was the favorite') }}</flux:heading>
                                <flux:text class="mt-1">
                                    {{ __(':picker chose it from :uploader’s three photos.', ['picker' => $pickerTask->assignee->name, 'uploader' => $favorite->task->assignee->name]) }}
                                </flux:text>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    @foreach ($this->latestResult->tasks as $resultTask)
                        <div class="app-glass-card rounded-2xl bg-zinc-50/80 p-5 backdrop-blur-xl ring-1 ring-black/[0.035] dark:bg-white/5 dark:ring-white/8">
                            <div class="flex items-center gap-2">
                                <flux:avatar circle size="xs" :name="$resultTask->assignee->name" :initials="$resultTask->assignee->initials()" />
                                <span class="text-sm font-medium">{{ $resultTask->assignee->name }}</span>
                            </div>
                            <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $resultTask->prompt }}</p>
                            <p class="mt-2 whitespace-pre-line text-zinc-900 dark:text-white">{{ $resultTask->questionResponse?->answer }}</p>
                            @if ($resultTask->photos->isNotEmpty())
                                <div class="mt-4 grid grid-cols-3 gap-2">
                                    @foreach ($resultTask->photos as $photo)
                                        <img src="{{ route('round-photos.show', $photo) }}" alt="{{ __('Photo shared by :name', ['name' => $resultTask->assignee->name]) }}" class="aspect-square w-full rounded-xl object-cover">
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @else
        <section class="prompt-surface-muted flex min-h-72 items-center justify-center p-8 text-center">
            <div class="max-w-sm">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-violet-50 text-violet-500 ring-8 ring-violet-50/60 dark:bg-violet-500/15 dark:text-violet-300 dark:ring-violet-500/5">
                    <flux:icon.heart class="size-6" />
                </span>
                <flux:heading size="lg" class="mt-6 tracking-tight">{{ __('Ready for your first prompt') }}</flux:heading>
                <flux:text class="mt-2 leading-6">{{ __('A shared question will appear here.') }}</flux:text>
            </div>
        </section>
    @endif
</div>
