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
        Flux::modal('temperature-check-in')->close();
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
    $myValue = $this->myLatest?->value;
    $partnerValue = $this->partnerLatest?->value;
    $myTint = $myValue ? 0.04 + ($myValue * 0.022) : 0.035;
    $partnerTint = $partnerValue ? 0.04 + ($partnerValue * 0.022) : 0.035;
    $myStickyTint = $myValue ? 0.06 + ($myValue * 0.035) : 0.045;
    $partnerStickyTint = $partnerValue ? 0.06 + ($partnerValue * 0.035) : 0.045;
    $myProgress = ($myValue ?? 0) * 10;
    $partnerProgress = ($partnerValue ?? 0) * 10;
@endphp

<section class="sticky z-30" data-temperature-card wire:poll.30s>
    <flux:modal.trigger name="temperature-check-in">
        <button
            type="button"
            class="prompt-surface block w-full p-5 text-left transition-[padding,box-shadow] duration-300 ease-out sm:p-6"
            data-temperature-shell
            aria-label="{{ __('Update your temperature') }}"
        >
            <div class="overflow-hidden transition-[max-height,opacity,margin] duration-300 ease-out" data-temperature-header>
                <div class="flex items-center justify-between gap-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-violet-500 ring-1 ring-violet-100 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/15">
                            <flux:icon.heart class="size-4" />
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-violet-600 dark:text-violet-300">{{ __('Temperature') }}</p>
                            <p class="mt-0.5 truncate text-sm text-zinc-500 dark:text-zinc-400">{{ __('A quick read on how you both feel.') }}</p>
                        </div>
                    </div>
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-950/5 text-zinc-400 dark:bg-white/8 dark:text-zinc-500">
                        <flux:icon.chevron-right class="size-4" />
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 transition-[margin,gap] duration-300 ease-out" data-temperature-grid>
                <div
                    class="relative overflow-hidden rounded-2xl border border-violet-100/70 p-4 transition-[padding,border-radius] duration-300 ease-out dark:border-violet-400/10"
                    aria-label="{{ $myValue ? __('Your temperature: :value out of 10, :label', ['value' => $myValue, 'label' => $this->labelFor($myValue)]) : __('Your temperature has not been shared') }}"
                    data-temperature-person
                >
                    <div class="absolute inset-0 bg-violet-500 transition-opacity duration-300" style="--temperature-tint: {{ $myTint }}; --temperature-sticky-tint: {{ $myStickyTint }};" data-temperature-tint></div>
                    <div class="relative">
                        <div class="flex items-center gap-2">
                            <flux:avatar size="xs" :name="auth()->user()->name" :initials="auth()->user()->initials()" />
                            <p class="truncate text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ auth()->user()->name }}</p>
                        </div>
                        <div class="mt-4 flex items-end justify-between gap-2 overflow-hidden transition-[max-height,opacity,margin] duration-300 ease-out" data-temperature-detail>
                            <div class="min-w-0">
                                <p class="text-2xl font-semibold tracking-[-0.04em] text-zinc-950 dark:text-white">{{ $myValue ?? '—' }}</p>
                                <p class="mt-0.5 truncate text-xs font-medium text-violet-700 dark:text-violet-300">{{ $myValue ? $this->labelFor($myValue) : __('Not shared') }}</p>
                            </div>
                            @if ($myValue)
                                <span class="pb-0.5 text-[0.6875rem] text-zinc-400">/ 10</span>
                            @endif
                        </div>
                        <div class="overflow-hidden opacity-0 transition-[max-height,opacity,margin] duration-300 ease-out" data-temperature-progress>
                            <div class="h-1 overflow-hidden rounded-full bg-violet-950/10 dark:bg-white/10">
                                <div class="h-full rounded-full bg-violet-500 transition-[width] duration-500" style="width: {{ $myProgress }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="relative overflow-hidden rounded-2xl border border-indigo-100/70 p-4 transition-[padding,border-radius] duration-300 ease-out dark:border-indigo-400/10"
                    aria-label="{{ $partnerValue ? __(':name’s temperature: :value out of 10, :label', ['name' => $this->partner?->name ?? __('Partner'), 'value' => $partnerValue, 'label' => $this->labelFor($partnerValue)]) : __(':name’s temperature has not been shared', ['name' => $this->partner?->name ?? __('Partner')]) }}"
                    data-temperature-person
                >
                    <div class="absolute inset-0 bg-indigo-500 transition-opacity duration-300" style="--temperature-tint: {{ $partnerTint }}; --temperature-sticky-tint: {{ $partnerStickyTint }};" data-temperature-tint></div>
                    <div class="relative">
                        <div class="flex items-center gap-2">
                            @if ($this->partner)
                                <flux:avatar size="xs" :name="$this->partner->name" :initials="$this->partner->initials()" />
                            @endif
                            <p class="truncate text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ $this->partner?->name ?? __('Partner') }}</p>
                        </div>
                        <div class="mt-4 flex items-end justify-between gap-2 overflow-hidden transition-[max-height,opacity,margin] duration-300 ease-out" data-temperature-detail>
                            <div class="min-w-0">
                                <p class="text-2xl font-semibold tracking-[-0.04em] text-zinc-950 dark:text-white">{{ $partnerValue ?? '—' }}</p>
                                <p class="mt-0.5 truncate text-xs font-medium text-indigo-700 dark:text-indigo-300">{{ $partnerValue ? $this->labelFor($partnerValue) : __('Not shared') }}</p>
                            </div>
                            @if ($partnerValue)
                                <span class="pb-0.5 text-[0.6875rem] text-zinc-400">/ 10</span>
                            @endif
                        </div>
                        <div class="overflow-hidden opacity-0 transition-[max-height,opacity,margin] duration-300 ease-out" data-temperature-progress>
                            <div class="h-1 overflow-hidden rounded-full bg-indigo-950/10 dark:bg-white/10">
                                <div class="h-full rounded-full bg-indigo-500 transition-[width] duration-500" style="width: {{ $partnerProgress }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </button>
    </flux:modal.trigger>

    <flux:modal name="temperature-check-in" focusable class="max-w-md">
        <form wire:submit="logTemperature" class="space-y-6">
            <div>
                <flux:heading size="xl" class="tracking-tight">{{ __('How are you feeling?') }}</flux:heading>
                <flux:subheading>{{ __('Share where you are right now with :name.', ['name' => $this->partner?->name ?? __('your partner')]) }}</flux:subheading>
            </div>

            <div class="rounded-2xl bg-violet-50/70 p-5 text-center ring-1 ring-violet-100 dark:bg-violet-500/10 dark:ring-violet-400/15">
                <p class="text-5xl font-semibold tracking-[-0.05em] text-violet-600 dark:text-violet-300">{{ $temperature }}</p>
                <p class="mt-1 text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $this->labelFor($temperature) }}</p>
            </div>

            <div class="px-1">
                <input type="range" min="1" max="10" step="1" wire:model.live="temperature" class="temperature-slider w-full" style="--temperature-progress: {{ $progress }}%;" aria-label="{{ __('Your temperature') }}" aria-valuetext="{{ $temperature }} out of 10, {{ $this->labelFor($temperature) }}">
                <div class="mt-3 flex justify-between text-[0.6875rem] font-medium text-zinc-400 dark:text-zinc-500">
                    <span>{{ __('Running low') }}</span>
                    <span>{{ __('Glowing') }}</span>
                </div>
            </div>

            <flux:error name="temperature" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="heart" wire:loading.attr="disabled" wire:target="logTemperature">
                    {{ $this->myLatest ? __('Update temperature') : __('Share temperature') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
