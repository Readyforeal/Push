<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Moments')] class extends Component {}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-8 pb-6 sm:pt-5">
    <header class="px-1">
        <div class="mb-3 flex items-center gap-2.5 text-xs font-semibold uppercase tracking-[0.16em] text-violet-600 dark:text-violet-300">
            <span class="size-1.5 rounded-full bg-violet-500 shadow-[0_0_0_4px_rgba(139,92,246,0.12)]"></span>
            <span>{{ __('Together') }}</span>
        </div>
        <h1 class="text-[2.15rem] font-semibold leading-none tracking-[-0.04em] text-zinc-950 sm:text-5xl dark:text-white">
            {{ __('Moments') }}
        </h1>
        <p class="mt-3 max-w-xl text-base leading-7 text-zinc-500 sm:text-lg dark:text-zinc-400">
            {{ __('A private record of the things you chose to keep.') }}
        </p>
    </header>

    <livewire:shared-moments :show-feed="true" />
</div>
