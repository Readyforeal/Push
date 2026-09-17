<?php

use App\Concerns\PreparesPhotoUploads;
use App\Enums\AppBackgroundMode;
use App\Models\PhotoSelection;
use App\Models\User;
use App\Services\PhotoStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Appearance settings')] class extends Component
{
    use PreparesPhotoUploads, WithFileUploads;

    public string $backgroundMode = AppBackgroundMode::None->value;

    public ?int $backgroundPhotoId = null;

    public $backgroundUpload;

    public function mount(): void
    {
        $user = $this->user();

        $this->backgroundMode = $user->background_mode === AppBackgroundMode::Auto
            ? AppBackgroundMode::None->value
            : ($user->background_mode ?? AppBackgroundMode::None)->value;
        $this->backgroundPhotoId = $user->background_photo_id;
    }

    /** @return Collection<int, PhotoSelection> */
    #[Computed]
    public function favorites(): Collection
    {
        $relationship = $this->user()->relationships()->first();

        if (! $relationship) {
            return new Collection;
        }

        return PhotoSelection::query()
            ->whereHas('task.round', function (Builder $query) use ($relationship): void {
                $query->where('relationship_id', $relationship->id);
            })
            ->with(['photo.task.assignee', 'task.assignee'])
            ->latest()
            ->get()
            ->unique('round_photo_id')
            ->values();
    }

    #[Computed]
    public function uploadedBackgroundUrl(): ?string
    {
        $user = $this->user();

        return $user->background_image_path ? $user->appBackgroundUrl() : null;
    }

    public function chooseMode(string $mode): void
    {
        abort_unless($mode === AppBackgroundMode::None->value, 422);

        $this->backgroundMode = $mode;
        $this->backgroundPhotoId = null;
    }

    public function choosePhoto(int $photoId): void
    {
        $this->backgroundMode = AppBackgroundMode::Photo->value;
        $this->backgroundPhotoId = $photoId;
    }

    public function chooseUpload(): void
    {
        $this->backgroundMode = AppBackgroundMode::Upload->value;
        $this->backgroundPhotoId = null;
    }

    public function updatedBackgroundUpload(PhotoStorage $photoStorage): void
    {
        $this->preparePhotoUploads('backgroundUpload', $photoStorage);

        if ($this->photoPreparationFailed('backgroundUpload')) {
            return;
        }

        $this->validateOnly('backgroundUpload', [
            'backgroundUpload' => ['required', 'file', 'extensions:jpg,jpeg,png,gif,webp,tif,tiff,dng,heic,heif', 'max:512000'],
        ]);

        $this->chooseUpload();
    }

    public function saveBackground(PhotoStorage $photoStorage): void
    {
        if ($this->photoPreparationFailed('backgroundUpload')) {
            $this->addError('backgroundUpload', __('Choose the failed photo again before saving your background.'));

            return;
        }

        $this->validate([
            'backgroundMode' => ['required', Rule::enum(AppBackgroundMode::class)],
            'backgroundPhotoId' => ['nullable', 'integer'],
            'backgroundUpload' => ['nullable', 'file', 'extensions:jpg,jpeg,png,gif,webp,tif,tiff,dng,heic,heif', 'max:512000'],
        ]);

        $mode = AppBackgroundMode::from($this->backgroundMode);
        $photoId = null;
        $user = $this->user();

        if ($mode === AppBackgroundMode::Photo) {
            $selection = $this->favorites->firstWhere('round_photo_id', $this->backgroundPhotoId);

            if (! $selection) {
                throw ValidationException::withMessages([
                    'backgroundPhotoId' => __('Choose a favorite from your shared library.'),
                ]);
            }

            $photoId = $selection->round_photo_id;
        }

        $attributes = [
            'background_mode' => $mode,
            'background_photo_id' => $photoId,
        ];
        $oldUploadPath = null;
        $oldUploadDisk = null;

        if ($mode === AppBackgroundMode::Upload) {
            if ($this->backgroundUpload) {
                $oldUploadPath = $user->background_image_path;
                $oldUploadDisk = $user->background_image_disk ?: 'local';
                $mediaDisk = (string) config('filesystems.media_disk', 'homelab_cloud');
                $attributes['background_image_disk'] = $mediaDisk;

                try {
                    $stored = $photoStorage->store($this->backgroundUpload, "backgrounds/{$user->id}", $mediaDisk);
                } catch (Throwable $exception) {
                    report($exception);

                    throw ValidationException::withMessages([
                        'backgroundUpload' => __('The background could not be saved. Please try again.'),
                    ]);
                }

                $attributes['background_image_path'] = $stored['path'];
                $attributes['background_image_mime_type'] = $stored['mime_type'];
            } elseif (! $user->background_image_path) {
                throw ValidationException::withMessages([
                    'backgroundUpload' => __('Choose an image to use as your background.'),
                ]);
            }
        }

        $user->update($attributes);

        if ($oldUploadPath && $oldUploadPath !== $user->background_image_path) {
            Storage::disk($oldUploadDisk)->delete($oldUploadPath);
        }

        $this->reset('backgroundUpload');

        $backgroundUrl = $user->fresh()->appBackgroundUrl();

        $this->dispatch(
            'app-background-updated',
            url: $backgroundUrl,
        );
        $this->dispatch('background-preference-saved');
    }

    public function removeUploadedBackground(): void
    {
        $user = $this->user();
        $path = $user->background_image_path;
        $disk = $user->background_image_disk ?: 'local';
        $mode = $user->background_mode === AppBackgroundMode::Upload
            ? AppBackgroundMode::None
            : ($user->background_mode ?? AppBackgroundMode::None);

        $user->update([
            'background_mode' => $mode,
            'background_image_disk' => null,
            'background_image_path' => null,
            'background_image_mime_type' => null,
        ]);

        if ($path) {
            Storage::disk($disk)->delete($path);
        }

        $this->backgroundMode = $mode->value;
        $this->reset('backgroundUpload');
        $this->dispatch('app-background-updated', url: $user->fresh()->appBackgroundUrl());
        $this->dispatch('background-preference-saved');
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Personalize how your shared space feels')">
        <div class="space-y-8">
            <div>
                <flux:heading>{{ __('Color mode') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Choose a light, dark, or system-matched interface.') }}</flux:text>

                <flux:radio.group x-data class="mt-4" variant="segmented" x-model="$flux.appearance">
                    <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
                    <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
                    <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
                </flux:radio.group>
            </div>

            <flux:separator />

            <form wire:submit="saveBackground" class="space-y-5">
                <div>
                    <flux:heading>{{ __('App background') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Choose no image, upload your own, or explicitly pin a favorite from your Library.') }}</flux:text>
                </div>

                <div>
                    <button
                        type="button"
                        wire:click="chooseMode('none')"
                        @class([
                            'app-glass-card flex min-h-24 w-full items-start gap-4 rounded-2xl border p-4 text-left transition',
                            'border-violet-500 bg-violet-50/80 ring-2 ring-violet-500/15 dark:border-violet-400 dark:bg-violet-500/10' => $backgroundMode === AppBackgroundMode::None->value,
                            'border-zinc-200 bg-white hover:border-violet-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-violet-500/60' => $backgroundMode !== AppBackgroundMode::None->value,
                        ])
                    >
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-white/8 dark:text-zinc-300">
                            <flux:icon.swatch class="size-5" />
                        </span>
                        <span>
                            <span class="block font-medium text-zinc-900 dark:text-white">{{ __('No photo') }}</span>
                            <span class="mt-1 block text-sm leading-5 text-zinc-500 dark:text-zinc-400">{{ __('Use the clean mauve app background instead.') }}</span>
                        </span>
                    </button>
                </div>

                <div @class([
                        'app-glass-card overflow-hidden rounded-2xl border transition',
                        'border-violet-500 ring-2 ring-violet-500/15 dark:border-violet-400' => $backgroundMode === AppBackgroundMode::Upload->value,
                        'border-zinc-200 dark:border-zinc-700' => $backgroundMode !== AppBackgroundMode::Upload->value,
                    ])
                >
                    <div class="relative aspect-[16/7] overflow-hidden bg-zinc-100 dark:bg-zinc-800">
                        @if ($backgroundUpload && ! $errors->has('backgroundUpload'))
                            <img src="{{ $backgroundUpload->temporaryUrl() }}" alt="{{ __('New custom background preview') }}" class="size-full object-cover">
                        @elseif ($this->uploadedBackgroundUrl)
                            <img src="{{ $this->uploadedBackgroundUrl }}" alt="{{ __('Your custom app background') }}" class="size-full object-cover">
                        @else
                            <div class="flex size-full items-center justify-center bg-gradient-to-br from-violet-100 via-zinc-100 to-zinc-200 text-zinc-400 dark:from-violet-950/40 dark:via-zinc-900 dark:to-zinc-800 dark:text-zinc-500">
                                <flux:icon.photo class="size-9" />
                            </div>
                        @endif

                        @if ($backgroundMode === AppBackgroundMode::Upload->value)
                            <span class="absolute right-3 top-3 flex size-8 items-center justify-center rounded-full bg-violet-600 text-white shadow-lg">
                                <flux:icon.check class="size-4" />
                            </span>
                        @endif

                        <div wire:loading.flex wire:target="backgroundUpload" class="absolute inset-0 flex-col items-center justify-center bg-black/35 px-8 text-white backdrop-blur-sm">
                            <p class="text-sm font-medium">{{ __('Preparing JPEG preview…') }}</p>
                        </div>
                    </div>

                    <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="font-medium text-zinc-900 dark:text-white">{{ __('Your own photo') }}</p>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('RAW, HEIC, and everyday photos are prepared as a large JPEG.') }}</p>
                        </div>

                        <div class="min-w-0 flex-1 sm:max-w-xs">
                            @if ($this->uploadedBackgroundUrl && ! $backgroundUpload)
                                <flux:button type="button" size="sm" wire:click="chooseUpload" class="mb-3">
                                    {{ __('Use image') }}
                                </flux:button>
                            @endif

                            <flux:input
                                type="file"
                                wire:model="backgroundUpload"
                                :label="$this->uploadedBackgroundUrl ? __('Replace image') : __('Choose image')"
                                accept="image/*,.dng,.raw,.heic,.heif,.tif,.tiff"
                            />

                            @if ($this->uploadedBackgroundUrl)
                                <flux:button type="button" size="sm" variant="ghost" class="mt-2" wire:click="removeUploadedBackground" wire:confirm="{{ __('Remove your uploaded background?') }}">
                                    {{ __('Remove') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>
                </div>

                <flux:error name="backgroundUpload" />

                @if ($this->favorites->isNotEmpty())
                    <div>
                        <p class="mb-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Or pin a favorite') }}</p>
                        <div class="grid grid-cols-3 gap-3 sm:grid-cols-4">
                            @foreach ($this->favorites as $selection)
                                <button
                                    type="button"
                                    wire:click="choosePhoto({{ $selection->round_photo_id }})"
                                    wire:key="background-photo-{{ $selection->round_photo_id }}"
                                    class="group relative aspect-[4/5] overflow-hidden rounded-2xl bg-zinc-100 transition dark:bg-zinc-800 {{ $backgroundMode === AppBackgroundMode::Photo->value && $backgroundPhotoId === $selection->round_photo_id ? 'ring-3 ring-violet-500 ring-offset-2 ring-offset-zinc-50 dark:ring-violet-400 dark:ring-offset-zinc-950' : 'ring-1 ring-black/5 hover:ring-violet-300 dark:ring-white/10' }}"
                                    aria-label="{{ __('Use this favorite as the app background') }}"
                                >
                                    <img
                                        src="{{ route('round-photos.show', $selection->photo) }}"
                                        alt="{{ __('Favorite shared by :name', ['name' => $selection->photo->task->assignee->name]) }}"
                                        class="size-full object-cover transition duration-500 group-hover:scale-105"
                                        loading="lazy"
                                    >
                                    @if ($backgroundMode === AppBackgroundMode::Photo->value && $backgroundPhotoId === $selection->round_photo_id)
                                        <span class="absolute right-2 top-2 flex size-7 items-center justify-center rounded-full bg-violet-600 text-white shadow-lg">
                                            <flux:icon.check class="size-4" />
                                        </span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="app-glass-card rounded-2xl border border-dashed border-zinc-300 p-5 text-center backdrop-blur-xl dark:border-zinc-700">
                        <flux:text>{{ __('Your pinned background choices will appear after you have favorites in the Library.') }}</flux:text>
                    </div>
                @endif

                <flux:error name="backgroundPhotoId" />

                <div class="flex items-center gap-4">
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="backgroundUpload,saveBackground">
                        <span wire:loading.remove wire:target="backgroundUpload,saveBackground">{{ __('Save background') }}</span>
                        <span wire:loading wire:target="backgroundUpload,saveBackground">{{ __('Working…') }}</span>
                    </flux:button>
                    <span
                        x-data="{ shown: false, timeout: null }"
                        x-on:background-preference-saved.window="shown = true; clearTimeout(timeout); timeout = setTimeout(() => shown = false, 2000)"
                        x-show="shown"
                        x-transition.opacity
                        x-cloak
                        class="text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        {{ __('Saved.') }}
                    </span>
                </div>
            </form>
        </div>
    </x-pages::settings.layout>
</section>
