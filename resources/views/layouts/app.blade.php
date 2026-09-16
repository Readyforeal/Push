<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main @class(['pb-32 lg:pb-0', 'max-lg:pt-0!' => request()->routeIs('moments.show')])>
        <div data-page-transition>
            {{ $slot }}
        </div>
    </flux:main>
</x-layouts::app.sidebar>
