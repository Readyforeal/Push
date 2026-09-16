<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @php($title = __('Welcome'))
        @include('partials.head')
    </head>
    <body class="min-h-svh overflow-x-hidden bg-zinc-50 text-zinc-950 antialiased dark:bg-zinc-950 dark:text-white">
        <main class="relative isolate flex min-h-svh flex-col overflow-hidden px-6 py-7 sm:px-10 sm:py-9" data-page-transition>
            <div class="pointer-events-none absolute inset-0 -z-20 bg-[radial-gradient(circle_at_50%_-10%,rgba(167,139,250,0.2),transparent_42%)] dark:bg-[radial-gradient(circle_at_50%_-10%,rgba(139,92,246,0.18),transparent_44%)]"></div>
            <div class="pointer-events-none absolute -left-32 top-[48%] -z-10 size-72 rounded-full bg-violet-200/35 blur-3xl dark:bg-violet-700/10"></div>
            <div class="pointer-events-none absolute -right-36 top-[18%] -z-10 size-80 rounded-full bg-indigo-200/35 blur-3xl dark:bg-indigo-700/10"></div>

            <header class="mx-auto flex w-full max-w-6xl items-center justify-between">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-4 dark:focus-visible:ring-offset-zinc-950" aria-label="{{ __('Push home') }}">
                    <span class="flex size-9 items-center justify-center rounded-xl bg-violet-600 text-white shadow-sm shadow-violet-900/20 dark:bg-violet-500">
                        <flux:icon.heart class="size-4.5" />
                    </span>
                    <span class="text-lg font-semibold tracking-[-0.025em]">Push</span>
                </a>

                @auth
                    <a href="{{ route('dashboard') }}" wire:navigate class="rounded-full px-4 py-2 text-sm font-semibold text-zinc-600 transition hover:bg-white/70 hover:text-violet-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 dark:text-zinc-300 dark:hover:bg-white/8 dark:hover:text-violet-300">
                        {{ __('Open app') }}
                    </a>
                @endauth
            </header>

            <div class="mx-auto flex w-full max-w-3xl flex-1 items-center justify-center py-16 sm:py-24">
                <section class="text-center">
                    <div class="mx-auto flex size-16 items-center justify-center rounded-[1.35rem] bg-white/75 text-violet-600 shadow-[0_20px_60px_rgba(76,29,149,0.12)] ring-1 ring-white backdrop-blur-xl dark:bg-white/8 dark:text-violet-300 dark:ring-white/10">
                        <flux:icon.heart class="size-7" />
                    </div>

                    <p class="mt-8 text-xs font-semibold uppercase tracking-[0.2em] text-violet-600 dark:text-violet-300">
                        {{ __('Made for two') }}
                    </p>
                    <h1 class="mx-auto mt-4 max-w-2xl text-4xl font-semibold leading-[1.05] tracking-[-0.055em] text-balance sm:text-6xl sm:leading-[1.02]">
                        {{ __('A little space to stay close.') }}
                    </h1>
                    <p class="mx-auto mt-6 max-w-lg text-base leading-7 text-zinc-500 text-pretty sm:text-lg sm:leading-8 dark:text-zinc-400">
                        {{ __('Daily prompts, shared moments, and thoughtful surprises—kept between you and your person.') }}
                    </p>

                    <div class="mt-9 flex justify-center">
                        @auth
                            <a href="{{ route('dashboard') }}" wire:navigate class="group inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-violet-600 px-6 text-sm font-semibold text-white shadow-lg shadow-violet-900/15 transition duration-200 hover:-translate-y-0.5 hover:bg-violet-700 hover:shadow-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-4 dark:bg-violet-500 dark:hover:bg-violet-400 dark:focus-visible:ring-offset-zinc-950">
                                {{ __('Open your shared space') }}
                                <flux:icon.arrow-right class="size-4 transition-transform group-hover:translate-x-0.5" />
                            </a>
                        @else
                            <a href="{{ route('login') }}" wire:navigate class="group inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-violet-600 px-7 text-sm font-semibold text-white shadow-lg shadow-violet-900/15 transition duration-200 hover:-translate-y-0.5 hover:bg-violet-700 hover:shadow-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-4 dark:bg-violet-500 dark:hover:bg-violet-400 dark:focus-visible:ring-offset-zinc-950">
                                {{ __('Log in to your space') }}
                                <flux:icon.arrow-right class="size-4 transition-transform group-hover:translate-x-0.5" />
                            </a>
                        @endauth
                    </div>
                </section>
            </div>

            <footer class="mx-auto w-full max-w-6xl text-center text-xs text-zinc-400 dark:text-zinc-600">
                {{ __('Private by design. Shared with one another.') }}
            </footer>
        </main>

        @fluxScripts
    </body>
</html>
