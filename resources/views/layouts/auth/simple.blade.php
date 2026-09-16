<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark bg-zinc-50 dark:bg-zinc-950">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-[100dvh] bg-zinc-50 text-zinc-950 antialiased dark:bg-zinc-950 dark:text-white">
        <main class="relative isolate flex min-h-[100dvh] flex-col overflow-hidden px-5 pb-[max(1.5rem,env(safe-area-inset-bottom))] pt-[max(1.5rem,env(safe-area-inset-top))] sm:px-8" data-page-transition>
            <div class="pointer-events-none absolute inset-0 -z-20 bg-[radial-gradient(circle_at_50%_-12%,rgba(244,114,182,0.2),transparent_40%)] dark:bg-[radial-gradient(circle_at_50%_-12%,rgba(236,72,153,0.17),transparent_42%)]"></div>
            <div class="pointer-events-none absolute -left-36 top-[55%] -z-10 size-72 rounded-full bg-pink-200/30 blur-3xl dark:bg-pink-800/10"></div>
            <div class="pointer-events-none absolute -right-40 top-[18%] -z-10 size-80 rounded-full bg-fuchsia-200/30 blur-3xl dark:bg-fuchsia-800/10"></div>

            <a href="{{ route('home') }}" class="mx-auto flex items-center gap-2.5 rounded-full font-semibold tracking-[-0.02em] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink-500 focus-visible:ring-offset-4 dark:focus-visible:ring-offset-zinc-950" wire:navigate aria-label="{{ __('Push home') }}">
                <span class="flex size-9 items-center justify-center rounded-xl bg-pink-600 text-white shadow-sm shadow-pink-900/20 dark:bg-pink-500">
                    <flux:icon.heart class="size-4.5" />
                </span>
                <span>Push</span>
            </a>

            <div class="flex flex-1 items-center justify-center py-8 sm:py-12">
                <div class="w-full max-w-md rounded-[2rem] border border-white/80 bg-white/78 p-6 shadow-[0_24px_80px_rgba(41,38,46,0.12)] backdrop-blur-2xl sm:p-8 dark:border-white/10 dark:bg-zinc-900/72 dark:shadow-[0_28px_90px_rgba(0,0,0,0.38)]">
                    <div class="flex flex-col gap-6">
                        {{ $slot }}
                    </div>
                </div>
            </div>

            <p class="text-center text-xs text-zinc-400 dark:text-zinc-600">
                {{ __('Private by design. Shared with one another.') }}
            </p>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
