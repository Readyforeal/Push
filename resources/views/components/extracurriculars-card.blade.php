<section class="prompt-surface p-5 sm:p-6">
    <div>
        <p class="prompt-kicker">{{ __('More for the two of you') }}</p>
        <h2 class="mt-1.5 text-xl font-semibold tracking-[-0.025em] text-zinc-950 dark:text-white">
            {{ __('Extracurriculars') }}
        </h2>
    </div>

    <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200/70 bg-white/45 dark:border-white/8 dark:bg-white/[0.025]">
        <a
            href="{{ route('missions') }}"
            wire:navigate.hover
            class="group flex items-center gap-3.5 px-3.5 py-3 transition duration-200 hover:bg-pink-50/75 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-pink-500 dark:hover:bg-pink-500/8"
        >
            <span class="flex size-11 shrink-0 items-center justify-center rounded-[0.9rem] bg-gradient-to-br from-pink-500 to-fuchsia-600 text-white shadow-sm shadow-pink-900/20">
                <flux:icon.gift class="size-5" />
            </span>

            <span class="min-w-0 flex-1">
                <span class="block text-sm font-semibold text-zinc-950 dark:text-white">{{ __('Secret Missions') }}</span>
                <span class="mt-0.5 block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ __('Choose a thoughtful surprise for your partner.') }}</span>
            </span>

            <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300 transition-transform duration-200 group-hover:translate-x-0.5 group-hover:text-pink-500 dark:text-zinc-600 dark:group-hover:text-pink-300" />
        </a>
    </div>
</section>
