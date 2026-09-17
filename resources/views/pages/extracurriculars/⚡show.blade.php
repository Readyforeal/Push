<?php

use App\Enums\PromptRoundOrigin;
use App\Enums\PromptRoundStatus;
use App\Models\PromptLibrary;
use App\Models\PromptRound;
use App\Models\Relationship;
use App\Models\User;
use App\Services\ExtracurricularPromptManager;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Extracurricular')] class extends Component
{
    public PromptLibrary $library;

    public function mount(PromptLibrary $library): void
    {
        abort_unless($this->relationship?->extracurricularLibraries()->whereKey($library->id)->exists(), 404);
        $this->library = $library;
    }

    public function requestPrompt(ExtracurricularPromptManager $manager): void
    {
        if (! $this->relationship) {
            return;
        }

        try {
            $round = $manager->request($this->user(), $this->relationship, $this->library);
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->redirectRoute('prompts.show', $round, navigate: true);
    }

    #[Computed]
    public function relationship(): ?Relationship
    {
        return $this->user()->relationships()->first();
    }

    #[Computed]
    public function activeRound(): ?PromptRound
    {
        return $this->relationship?->rounds()
            ->where('origin', PromptRoundOrigin::Extracurricular)
            ->where('status', PromptRoundStatus::Active)
            ->first();
    }

    private function user(): User
    {
        return Auth::user();
    }
}; ?>

<div class="mx-auto w-full max-w-3xl pb-8 sm:pt-5">
    <header class="px-1">
        <a href="{{ route('dashboard') }}" wire:navigate.hover class="mb-6 inline-flex size-10 items-center justify-center rounded-full bg-white/65 text-zinc-800 shadow-sm ring-1 ring-black/5 backdrop-blur-xl dark:bg-white/8 dark:text-white dark:ring-white/10">
            <flux:icon.arrow-left class="size-5" />
        </a>
        <p class="prompt-kicker">{{ __('On demand') }}</p>
        <h1 class="mt-2 text-[2.15rem] font-semibold leading-none tracking-[-0.04em] text-zinc-950 sm:text-5xl dark:text-white">{{ $library->name }}</h1>
        @if ($library->description)
            <p class="mt-3 max-w-xl text-base leading-7 text-zinc-500 sm:text-lg dark:text-zinc-400">{{ $library->description }}</p>
        @endif
    </header>

    <section class="prompt-surface mt-8 p-6 sm:p-8">
        @if ($this->activeRound)
            <flux:heading size="xl">{{ __('An extracurricular is already in progress') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Finish it before drawing another. Your prompt will stay waiting for you.') }}</flux:text>
            <flux:button :href="route('prompts.show', $this->activeRound)" wire:navigate.hover variant="primary" icon="arrow-right" class="mt-6">
                {{ __('Continue prompt') }}
            </flux:button>
        @else
            <span class="flex size-14 items-center justify-center rounded-2xl bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300">
                <flux:icon.sparkles class="size-6" />
            </span>
            <flux:heading size="xl" class="mt-6">{{ __('Draw one prompt') }}</flux:heading>
            <flux:text class="mt-2 max-w-lg leading-6">{{ __('You can request one extracurricular prompt per day. Once drawn, it stays with you until both of you finish it.') }}</flux:text>
            <flux:button type="button" wire:click="requestPrompt" variant="primary" icon="sparkles" class="mt-6">
                {{ __('Give us a prompt') }}
            </flux:button>
        @endif
    </section>
</div>
