<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate.hover>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate.hover>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('relationship.edit')" wire:navigate.hover>{{ __('Relationship') }}</flux:navlist.item>
            <flux:navlist.item :href="route('prompt-schedule.edit')" wire:navigate.hover>{{ __('Prompt schedule') }}</flux:navlist.item>
            <flux:navlist.item :href="route('prompt-libraries.edit')" wire:navigate.hover>{{ __('Prompt libraries') }}</flux:navlist.item>
            <flux:navlist.item :href="route('notifications.edit')" wire:navigate.hover>{{ __('Notifications') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate.hover>{{ __('Appearance') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-2xl" data-page-stagger>
            {{ $slot }}
        </div>
    </div>
</div>
