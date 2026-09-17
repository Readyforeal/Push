@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="Push" data-app-brand {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-xl bg-violet-600 text-white shadow-sm shadow-violet-900/20 dark:bg-violet-500">
            <x-app-logo-icon class="size-4.5" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="Push" data-app-brand {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-xl bg-violet-600 text-white shadow-sm shadow-violet-900/20 dark:bg-violet-500">
            <x-app-logo-icon class="size-4.5" />
        </x-slot>
    </flux:brand>
@endif
