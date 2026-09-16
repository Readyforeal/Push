<div class="w-full max-w-2xl">
    <div>
        <flux:heading size="xl" level="1">{{ $heading ?? '' }}</flux:heading>
        <flux:subheading size="lg" class="mt-1">{{ $subheading ?? '' }}</flux:subheading>
    </div>

    <div class="mt-6 w-full" data-page-stagger>
        {{ $slot }}
    </div>
</div>
