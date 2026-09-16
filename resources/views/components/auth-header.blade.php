@props([
    'title',
    'description',
])

<div class="flex w-full flex-col text-center">
    <flux:heading size="xl" level="1" class="text-2xl! font-semibold! tracking-[-0.035em] sm:text-3xl!">{{ $title }}</flux:heading>
    <flux:subheading class="mx-auto mt-2 max-w-sm leading-6">{{ $description }}</flux:subheading>
</div>
