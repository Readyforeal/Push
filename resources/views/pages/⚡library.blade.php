<?php

use App\Models\PhotoSelection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Library')] class extends Component
{
    /** @return Collection<int, PhotoSelection> */
    #[Computed]
    public function favorites(): Collection
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        $relationship = $user->relationships()->first();

        if (! $relationship) {
            return new Collection;
        }

        return PhotoSelection::query()
            ->whereHas('task.round', function (Builder $query) use ($relationship): void {
                $query->where('relationship_id', $relationship->id);
            })
            ->with([
                'photo.task.assignee',
                'task.assignee',
                'task.round',
            ])
            ->latest()
            ->get();
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-8 pb-6 sm:pt-5">
    <header class="flex items-end justify-between gap-4 px-1">
        <div>
            <div class="mb-3 flex items-center gap-2.5 text-xs font-semibold uppercase tracking-[0.16em] text-pink-600 dark:text-pink-300">
                <span class="size-1.5 rounded-full bg-pink-500 shadow-[0_0_0_4px_rgba(236,72,153,0.12)]"></span>
                <span>{{ __('Together') }}</span>
            </div>
            <h1 class="text-[2.15rem] font-semibold leading-none tracking-[-0.04em] text-zinc-950 sm:text-5xl dark:text-white">
                {{ __('Library') }}
            </h1>
            <p class="mt-3 max-w-xl text-base leading-7 text-zinc-500 sm:text-lg dark:text-zinc-400">
                {{ __('A collection of the photos you chose together.') }}
            </p>
        </div>

        @if ($this->favorites->isNotEmpty())
            <span class="mb-1 hidden rounded-full bg-zinc-100 px-3 py-1.5 text-xs font-medium text-zinc-500 sm:inline dark:bg-white/8 dark:text-zinc-400">
                {{ trans_choice(':count favorite|:count favorites', $this->favorites->count(), ['count' => $this->favorites->count()]) }}
            </span>
        @endif
    </header>

    @if ($this->favorites->isNotEmpty())
        <div class="grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-3">
            @foreach ($this->favorites as $selection)
                @php
                    $photo = $selection->photo;
                    $picker = $selection->task->assignee;
                    $uploader = $photo->task->assignee;
                    $modalName = 'favorite-photo-'.$selection->id;
                @endphp

                <article class="app-glass-card group overflow-hidden rounded-[1.5rem] bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03),0_12px_35px_rgba(15,23,42,0.07)] ring-1 ring-black/[0.035] backdrop-blur-xl transition duration-300 hover:-translate-y-1 hover:shadow-[0_20px_45px_rgba(15,23,42,0.12)] dark:bg-zinc-900 dark:ring-white/10 dark:hover:shadow-black/30">
                    <flux:modal.trigger :name="$modalName">
                        <button type="button" class="block w-full cursor-zoom-in overflow-hidden text-left">
                            <div class="relative aspect-[4/5] overflow-hidden bg-zinc-100 dark:bg-zinc-800">
                                <img
                                    src="{{ route('round-photos.show', $photo) }}"
                                    alt="{{ __('A favorite photo uploaded by :name', ['name' => $uploader->name]) }}"
                                    class="size-full object-cover transition duration-700 ease-out group-hover:scale-[1.025]"
                                    loading="lazy"
                                >
                                <div class="absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-t from-black/30 to-transparent opacity-0 transition duration-300 group-hover:opacity-100"></div>
                                <span class="absolute bottom-3 right-3 flex size-8 translate-y-1 items-center justify-center rounded-full bg-white/90 text-zinc-700 opacity-0 shadow-sm backdrop-blur transition duration-300 group-hover:translate-y-0 group-hover:opacity-100">
                                    <flux:icon.arrows-pointing-out class="size-4" />
                                </span>
                            </div>

                            <div class="p-3.5 sm:p-4">
                                <p class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">
                                    {{ __('Picked by :name', ['name' => $picker->name]) }}
                                </p>
                                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                                    {{ ($selection->task->round->revealed_at ?? $selection->created_at)?->format('M j, Y') }}
                                </p>
                            </div>
                        </button>
                    </flux:modal.trigger>

                    <flux:modal :name="$modalName" class="max-w-4xl !p-0">
                        <div class="overflow-hidden rounded-2xl bg-black">
                            <img
                                src="{{ route('round-photos.show', $photo) }}"
                                alt="{{ __('A favorite photo uploaded by :name', ['name' => $uploader->name]) }}"
                                class="max-h-[78vh] w-full object-contain"
                            >
                        </div>
                        <div class="flex items-center justify-between gap-4 px-1 pb-1 pt-4">
                            <div>
                                <flux:heading>{{ __('Picked by :name', ['name' => $picker->name]) }}</flux:heading>
                                <flux:text class="mt-1">{{ __('Shared by :name', ['name' => $uploader->name]) }}</flux:text>
                            </div>
                            <flux:text class="text-xs">{{ ($selection->task->round->revealed_at ?? $selection->created_at)?->format('M j, Y') }}</flux:text>
                        </div>
                    </flux:modal>
                </article>
            @endforeach
        </div>
    @else
        <section class="prompt-surface-muted flex min-h-80 items-center justify-center p-8 text-center">
            <div class="max-w-sm">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-pink-50 text-pink-500 ring-8 ring-pink-50/60 dark:bg-pink-500/15 dark:text-pink-300 dark:ring-pink-500/5">
                    <flux:icon.photo class="size-6" />
                </span>
                <flux:heading size="lg" class="mt-6 tracking-tight">{{ __('Your favorites will live here') }}</flux:heading>
                <flux:text class="mt-2 leading-6">
                    {{ __('When one of you picks a favorite photo, it will be saved to your shared library.') }}
                </flux:text>
            </div>
        </section>
    @endif
</div>
