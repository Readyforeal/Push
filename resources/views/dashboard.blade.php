<x-layouts::app :title="__('Dashboard')">
    @php
        $user = auth()->user();
        $relationship = $user->relationships()->first();
        $today = now($relationship?->timezone ?? config('app.timezone'));
        $greeting = match (true) {
            $today->hour < 12 => __('Good morning'),
            $today->hour < 17 => __('Good afternoon'),
            default => __('Good evening'),
        };
    @endphp

    <div class="home-shell mx-auto flex w-full max-w-3xl flex-1 flex-col gap-8 pb-6 sm:gap-10 sm:pt-5" data-home-screen>
        <div class="relative px-1">
            <div class="mb-4 flex items-center gap-2.5 text-xs font-semibold uppercase tracking-[0.16em] text-violet-600 dark:text-violet-300">
                <span class="size-1.5 rounded-full bg-violet-500 shadow-[0_0_0_4px_rgba(139,92,246,0.12)]"></span>
                <span>{{ $today->isoFormat('dddd, MMMM D') }}</span>
            </div>

            <h1 class="max-w-2xl text-[2.15rem] font-semibold leading-[1.08] tracking-[-0.04em] text-zinc-950 sm:text-5xl dark:text-white">
                {{ __(':greeting, :name.', ['greeting' => $greeting, 'name' => $user->name]) }}
            </h1>
            <p class="mt-3 max-w-lg text-base leading-7 text-zinc-500 sm:text-lg dark:text-zinc-400">
                {{ __('A little space to slow down and stay close.') }}
            </p>
        </div>

        <div class="relative space-y-6" data-home-card-stack>
            @if ($relationship)
                <livewire:temperature-gauge />
                <livewire:shared-moments :show-composer="false" />
                <livewire:current-prompt :summary="true" />
                <x-extracurriculars-card />
            @else
                <livewire:relationship-invitation />
            @endif
        </div>
    </div>
</x-layouts::app>
