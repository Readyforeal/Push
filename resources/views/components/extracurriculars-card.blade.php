@php
    $relationship = auth()->user()?->relationships()->first();
    $promptLibraries = $relationship?->extracurricularLibraries()
        ->where('active', true)
        ->orderBy('name')
        ->get() ?? collect();
@endphp

<section class="prompt-surface p-5 sm:p-6">
    <div>
        <p class="prompt-kicker">{{ __('More for the two of you') }}</p>
        <h2 class="mt-1.5 text-xl font-semibold tracking-[-0.025em] text-zinc-950 dark:text-white">
            {{ __('Extracurriculars') }}
        </h2>
        <p class="mt-1 text-sm leading-5 text-zinc-500 dark:text-zinc-400">
            {{ __('Choose something extra and draw one prompt on demand.') }}
        </p>
    </div>

    <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200/70 bg-white/45 dark:border-white/8 dark:bg-white/[0.025]">
        <a
            href="{{ route('missions') }}"
            wire:navigate.hover
            class="group flex items-center gap-3.5 px-3.5 py-3 transition duration-200 hover:bg-violet-50/75 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-violet-500 dark:hover:bg-violet-500/8"
        >
            <span class="flex size-11 shrink-0 items-center justify-center rounded-[0.9rem] bg-gradient-to-br from-violet-500 to-indigo-600 text-white shadow-sm shadow-violet-900/20">
                <flux:icon.gift class="size-5" />
            </span>

            <span class="min-w-0 flex-1">
                <span class="block text-sm font-semibold text-zinc-950 dark:text-white">{{ __('Secret Missions') }}</span>
                <span class="mt-0.5 block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ __('Choose a thoughtful surprise for your partner.') }}</span>
            </span>

            <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300 transition-transform duration-200 group-hover:translate-x-0.5 group-hover:text-violet-500 dark:text-zinc-600 dark:group-hover:text-violet-300" />
        </a>

        @foreach ($promptLibraries as $library)
            <a
                href="{{ route('extracurriculars.show', $library) }}"
                wire:navigate.hover
                class="group flex items-center gap-3.5 border-t border-zinc-200/70 px-3.5 py-3 transition duration-200 hover:bg-violet-50/75 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-violet-500 dark:border-white/8 dark:hover:bg-violet-500/8"
            >
                <span class="flex size-11 shrink-0 items-center justify-center rounded-[0.9rem] bg-gradient-to-br from-fuchsia-500 to-violet-600 text-white shadow-sm shadow-violet-900/20">
                    <flux:icon.sparkles class="size-5" />
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-zinc-950 dark:text-white">{{ $library->name }}</span>
                    <span class="mt-0.5 block truncate text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $library->description ?: __('A little something for the two of you.') }}
                    </span>
                </span>

                <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300 transition-transform duration-200 group-hover:translate-x-0.5 group-hover:text-violet-500 dark:text-zinc-600 dark:group-hover:text-violet-300" />
            </a>
        @endforeach
    </div>
</section>
