@php
    $items = [
        [
            'label' => __('Home'),
            'route' => 'dashboard',
            'icon' => 'home',
            'current' => request()->routeIs('dashboard'),
        ],
        [
            'label' => __('History'),
            'route' => 'history',
            'icon' => 'clock',
            'current' => request()->routeIs('history'),
        ],
        [
            'label' => __('Moments'),
            'route' => 'moments',
            'icon' => 'sparkles',
            'current' => request()->routeIs('moments*'),
        ],
        [
            'label' => __('Library'),
            'route' => 'library',
            'icon' => 'photo',
            'current' => request()->routeIs('library'),
        ],
    ];
@endphp

<div
    data-mobile-dock
    class="fixed inset-x-4 z-50 lg:hidden"
    style="bottom: calc(0.75rem + 4pt);"
>
    <nav
        aria-label="{{ __('Primary navigation') }}"
        class="mx-auto flex max-w-md items-center gap-1 rounded-full border border-violet-200/55 bg-white/65 p-1.5 shadow-xl shadow-violet-950/10 backdrop-blur-xl dark:border-violet-400/15 dark:bg-zinc-900/65 dark:shadow-black/30"
    >
        @foreach ($items as $item)
            <a
                href="{{ route($item['route']) }}"
                data-dock-item="{{ $item['route'] }}"
                wire:navigate.hover
                @if ($item['current']) aria-current="page" @endif
                @class([
                    'flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 rounded-full px-1 text-[0.6875rem] font-medium transition-all duration-200 sm:px-2 sm:text-xs',
                    'bg-violet-600 text-white shadow-sm shadow-violet-900/20 dark:bg-violet-500 dark:text-white' => $item['current'],
                    'text-zinc-500 hover:bg-violet-50 hover:text-violet-700 dark:text-zinc-400 dark:hover:bg-violet-500/10 dark:hover:text-violet-300' => ! $item['current'],
                ])
            >
                <flux:icon :name="$item['icon']" class="size-5" />
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
</div>
