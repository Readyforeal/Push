<?php

use App\Models\Relationship;
use App\Models\TemperatureCheckIn;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $temperature = 5;

    public function mount(): void
    {
        $this->temperature = $this->myLatest?->value ?? 5;
    }

    public function logTemperature(): void
    {
        $validated = $this->validate([
            'temperature' => ['required', 'integer', 'between:1,10'],
        ]);

        $relationship = $this->relationship;

        if (! $relationship) {
            return;
        }

        $relationship->temperatureCheckIns()->create([
            'user_id' => $this->user()->id,
            'value' => $validated['temperature'],
        ]);

        unset($this->myLatest);
        Flux::toast(variant: 'success', text: __('Temperature shared.'));
    }

    #[Computed]
    public function relationship(): ?Relationship
    {
        return $this->user()->relationships()->with('members')->first();
    }

    #[Computed]
    public function partner(): ?User
    {
        return $this->relationship?->members->firstWhere('id', '!=', $this->user()->id);
    }

    #[Computed]
    public function myLatest(): ?TemperatureCheckIn
    {
        return $this->latestFor($this->user());
    }

    #[Computed]
    public function partnerLatest(): ?TemperatureCheckIn
    {
        return $this->partner ? $this->latestFor($this->partner) : null;
    }

    public function labelFor(int $value): string
    {
        return match (true) {
            $value <= 2 => __('Running low'),
            $value <= 4 => __('A little tender'),
            $value <= 6 => __('Steady'),
            $value <= 8 => __('Warm'),
            default => __('Glowing'),
        };
    }

    private function latestFor(User $user): ?TemperatureCheckIn
    {
        return $this->relationship?->temperatureCheckIns()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}; ?>

@php
    $progress = (($temperature - 1) / 9) * 100;
    $partnerProgress = $this->partnerLatest ? (($this->partnerLatest->value - 1) / 9) * 100 : 0;
@endphp

<section class="prompt-surface p-6 sm:p-7" wire:poll.30s>
    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-pink-600 dark:text-pink-300">
                <flux:icon.heart class="size-3.5" />
                <span>{{ __('Temperature check-in') }}</span>
            </div>
            <flux:heading size="xl" class="mt-3 tracking-tight">{{ __('How are you feeling?') }}</flux:heading>
            <flux:text class="mt-1.5 leading-6">
                {{ __('Share where you are right now with :name.', ['name' => $this->partner?->name ?? __('your partner')]) }}
            </flux:text>
        </div>

        <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-pink-50 text-pink-500 ring-1 ring-pink-100 dark:bg-pink-500/10 dark:text-pink-300 dark:ring-pink-400/15">
            <flux:icon.fire class="size-5" />
        </div>
    </div>

    <form wire:submit="logTemperature" class="mt-7">
        <div class="flex items-end justify-between gap-4">
            <div>
                <p class="text-xs font-medium text-zinc-400 dark:text-zinc-500">{{ __('Your temperature') }}</p>
                <p class="mt-1 text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $this->labelFor($temperature) }}</p>
            </div>
            <div class="flex items-baseline gap-1 text-pink-600 dark:text-pink-300" aria-hidden="true">
                <span class="text-3xl font-semibold tracking-[-0.04em]">{{ $temperature }}</span>
                <span class="text-sm font-medium opacity-60">/ 10</span>
            </div>
        </div>

        <div class="mt-5 px-1">
            <input
                type="range"
                min="1"
                max="10"
                step="1"
                wire:model.live="temperature"
                class="temperature-slider w-full"
                style="--temperature-progress: {{ $progress }}%;"
                aria-label="{{ __('Your temperature') }}"
                aria-valuetext="{{ $temperature }} out of 10, {{ $this->labelFor($temperature) }}"
            >
            <div class="mt-2.5 flex justify-between text-[0.6875rem] font-medium text-zinc-400 dark:text-zinc-500">
                <span>{{ __('Running low') }}</span>
                <span>{{ __('Glowing') }}</span>
            </div>
        </div>

        <div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-h-5 text-xs text-zinc-400 dark:text-zinc-500">
                @if ($this->myLatest)
                    {{ __('Last shared :time', ['time' => $this->myLatest->created_at->diffForHumans()]) }}
                @endif
            </div>
            <flux:button type="submit" variant="primary" icon="heart" wire:loading.attr="disabled" wire:target="logTemperature">
                {{ $this->myLatest ? __('Update my temperature') : __('Share my temperature') }}
            </flux:button>
        </div>
    </form>

    <div class="my-6 h-px bg-zinc-100 dark:bg-white/8"></div>

    <div class="app-glass-card rounded-2xl bg-zinc-50/80 p-4 backdrop-blur-xl ring-1 ring-black/[0.035] dark:bg-white/5 dark:ring-white/8">
        <div class="flex items-center gap-3">
            @if ($this->partner)
                <flux:avatar size="sm" :name="$this->partner->name" :initials="$this->partner->initials()" />
            @endif

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">
                    {{ __(':name’s temperature', ['name' => $this->partner?->name ?? __('Partner')]) }}
                </p>
                @if ($this->partnerLatest)
                    <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                        {{ __('Shared :time', ['time' => $this->partnerLatest->created_at->diffForHumans()]) }}
                    </p>
                @else
                    <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">{{ __('No check-in shared yet') }}</p>
                @endif
            </div>

            @if ($this->partnerLatest)
                <div class="text-right">
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $this->partnerLatest->value }} / 10</p>
                    <p class="mt-0.5 text-xs font-medium text-pink-600 dark:text-pink-300">{{ $this->labelFor($this->partnerLatest->value) }}</p>
                </div>
            @endif
        </div>

        @if ($this->partnerLatest)
            <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                <div class="h-full rounded-full bg-gradient-to-r from-pink-300 to-pink-500 transition-all duration-500" style="width: {{ $partnerProgress }}%"></div>
            </div>
        @endif
    </div>
</section>
