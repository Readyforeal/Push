<?php

use App\Models\PromptRound;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Prompt')] class extends Component
{
    public PromptRound $round;

    public function mount(PromptRound $round): void
    {
        abort_unless($round->relationship->hasMember(Auth::user()), 403);
        $this->round = $round;
    }
}; ?>

<div class="mx-auto w-full max-w-3xl pb-8 sm:pt-5">
    <div class="mb-5 flex items-center gap-3 px-1">
        <a href="{{ route('dashboard') }}" wire:navigate.hover class="inline-flex size-10 items-center justify-center rounded-full bg-white/65 text-zinc-800 shadow-sm ring-1 ring-black/5 backdrop-blur-xl transition hover:bg-violet-100 hover:text-violet-700 dark:bg-white/8 dark:text-white dark:ring-white/10">
            <flux:icon.arrow-left class="size-5" />
        </a>
        <div>
            <p class="prompt-kicker">{{ $round->origin->value === 'extracurricular' ? __('Extracurricular') : __('Daily prompt') }}</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-[-0.035em] text-zinc-950 dark:text-white">{{ __('Your prompt') }}</h1>
        </div>
    </div>

    <livewire:current-prompt :round-id="$round->id" :key="'prompt-round-'.$round->id" />
</div>
