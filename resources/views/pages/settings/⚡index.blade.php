<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Settings')] class extends Component {
    // This page is the single entry point for app and account settings.
}; ?>

@php
    $settings = [
        [
            'title' => __('Profile'),
            'description' => __('Your name, email address, and account.'),
            'route' => 'profile.edit',
            'icon' => 'user',
            'color' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
        ],
        [
            'title' => __('Security'),
            'description' => __('Password, passkeys, and two-factor authentication.'),
            'route' => 'security.edit',
            'icon' => 'shield-check',
            'color' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        ],
        [
            'title' => __('Relationship'),
            'description' => __('Your partner connection and shared timezone.'),
            'route' => 'relationship.edit',
            'icon' => 'heart',
            'color' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
        ],
        [
            'title' => __('Notifications'),
            'description' => __('Manage push notifications on this device.'),
            'route' => 'notifications.edit',
            'icon' => 'bell',
            'color' => 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-500/15 dark:text-fuchsia-300',
        ],
        [
            'title' => __('Appearance'),
            'description' => __('Color mode and your app background.'),
            'route' => 'appearance.edit',
            'icon' => 'paint-brush',
            'color' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
        ],
    ];

    if (auth()->user()?->is_admin) {
        array_splice($settings, 3, 0, [[
            'title' => __('Prompt schedule'),
            'description' => __('Choose what runs each day and when.'),
            'route' => 'prompt-schedule.edit',
            'icon' => 'calendar',
            'color' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        ], [
            'title' => __('Prompt libraries'),
            'description' => __('Curate and import questions by category.'),
            'route' => 'prompt-libraries.edit',
            'icon' => 'book-open',
            'color' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
        ], [
            'title' => __('Prompt temperature'),
            'description' => __('Set which prompt tags are safe at each temperature.'),
            'route' => 'prompt-temperature.edit',
            'icon' => 'adjustments-horizontal',
            'color' => 'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300',
        ], [
            'title' => __('Activity log'),
            'description' => __('See recent page visits across the app.'),
            'route' => 'activity-log.index',
            'icon' => 'chart-bar-square',
            'color' => 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300',
        ]]);
    }
@endphp

<section class="mx-auto w-full max-w-3xl">
    <header class="px-1">
        <p class="prompt-kicker">{{ __('Your space') }}</p>
        <flux:heading size="xl" level="1" class="mt-2">{{ __('Settings') }}</flux:heading>
        <flux:subheading size="lg" class="mt-2">{{ __('Make Push feel right for both of you.') }}</flux:subheading>
    </header>

    <nav aria-label="{{ __('Settings') }}" class="prompt-surface mt-7 divide-y divide-zinc-200/70 dark:divide-white/8" data-page-stagger>
        @foreach ($settings as $setting)
            <a
                href="{{ route($setting['route']) }}"
                wire:navigate.hover
                class="group flex items-center gap-4 px-4 py-4 transition first:rounded-t-[1.75rem] last:rounded-b-[1.75rem] hover:bg-violet-50/65 sm:px-5 dark:hover:bg-violet-500/8"
            >
                <span class="flex size-11 shrink-0 items-center justify-center rounded-2xl {{ $setting['color'] }}">
                    <flux:icon :name="$setting['icon']" class="size-5" />
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold text-zinc-950 dark:text-white">{{ $setting['title'] }}</span>
                    <span class="mt-0.5 block text-sm leading-5 text-zinc-500 dark:text-zinc-400">{{ $setting['description'] }}</span>
                </span>

                <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-400 transition-transform group-hover:translate-x-0.5 dark:text-zinc-500" />
            </a>
        @endforeach
    </nav>
</section>
