<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notifications')] class extends Component {
    // Push subscription management is handled by the browser Push API.
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Notifications') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Notifications')" :subheading="__('Manage push notifications for this device')">
        <div
            data-push-panel
            data-vapid-key="{{ config('webpush.vapid.public_key') }}"
            data-subscribe-url="{{ route('push.subscriptions.store') }}"
            data-unsubscribe-url="{{ route('push.subscriptions.destroy') }}"
            data-send-url="{{ route('push.send') }}"
            class="space-y-6"
        >
            <div>
                <div class="flex items-start justify-between gap-4">
                    <flux:heading size="lg">{{ __('Push notifications') }}</flux:heading>
                    <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        {{ __('Requires iOS 16.4+') }}
                    </span>
                </div>

                <p
                    data-push-status
                    role="status"
                    class="mt-2 text-sm text-zinc-600 data-[type=error]:text-red-600 data-[type=success]:text-emerald-600 data-[type=warning]:text-amber-600 dark:text-zinc-300 dark:data-[type=error]:text-red-400 dark:data-[type=success]:text-emerald-400 dark:data-[type=warning]:text-amber-400"
                >
                    {{ __('Loading push support…') }}
                </p>

                <div class="mt-4 flex flex-wrap gap-3">
                    <flux:button data-push-enable variant="primary" icon="bell">
                        {{ __('Enable notifications') }}
                    </flux:button>
                    <flux:button data-push-disable variant="ghost" icon="bell-slash" hidden>
                        {{ __('Disable on this device') }}
                    </flux:button>
                </div>
            </div>

            <flux:separator variant="subtle" />

            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Send a test notification') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Send a push to every device subscribed to your account.') }}</flux:text>
                </div>

                <flux:input
                    data-push-title
                    :label="__('Title')"
                    value="Hello from Servo"
                    maxlength="80"
                />
                <flux:textarea
                    data-push-body
                    :label="__('Message')"
                    maxlength="200"
                    rows="3"
                >Your Laravel app sent a real Web Push notification.</flux:textarea>

                <flux:button data-push-send variant="primary" icon="paper-airplane" disabled>
                    {{ __('Send test notification') }}
                </flux:button>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
