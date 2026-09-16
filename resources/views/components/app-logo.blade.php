@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="Push" data-app-brand {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-xl bg-pink-600 text-white shadow-sm shadow-pink-900/20 dark:bg-pink-500">
            <flux:icon.heart class="size-4" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="Push" data-app-brand {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-xl bg-pink-600 text-white shadow-sm shadow-pink-900/20 dark:bg-pink-500">
            <flux:icon.heart class="size-4" />
        </x-slot>
    </flux:brand>
@endif
