<?php

use App\Models\Relationship;
use App\Models\User;
use App\Services\RelationshipPairing;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Relationship')] class extends Component {
    public string $timezone = 'UTC';

    public function mount(): void
    {
        $this->timezone = $this->relationship?->timezone ?? config('app.timezone', 'UTC');
    }

    public function updateTimezone(RelationshipPairing $pairing): void
    {
        $this->validate(['timezone' => ['required', 'timezone']]);

        if (! $this->relationship) {
            return;
        }

        $pairing->updateTimezone($this->user(), $this->relationship, $this->timezone);
        unset($this->relationship);
        Flux::toast(variant: 'success', text: __('Timezone updated.'));
    }

    #[Computed]
    public function relationship(): ?Relationship
    {
        return $this->user()->relationships()
            ->with('members')
            ->first();
    }

    #[Computed]
    public function partner(): ?User
    {
        return $this->relationship?->members->firstWhere('id', '!=', $this->user()->id);
    }

    /** @return array<string, string> */
    public function timezones(): array
    {
        return collect(DateTimeZone::listIdentifiers())
            ->mapWithKeys(fn (string $timezone) => [$timezone => str_replace('_', ' ', $timezone)])
            ->all();
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Relationship') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Relationship')" :subheading="__('Connect with your partner and manage your shared timezone')">
        <div class="space-y-6">
            @if ($this->partner)
                <div class="app-glass-card rounded-xl border border-zinc-200 p-5 backdrop-blur-xl dark:border-zinc-700">
                    <div class="flex items-center gap-3">
                        <flux:avatar circle :name="$this->partner->name" :initials="$this->partner->initials()" />
                        <div>
                            <flux:heading>{{ $this->partner->name }}</flux:heading>
                            <flux:text>{{ $this->partner->email }}</flux:text>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400">
                        <flux:icon.check-circle class="size-4" />
                        {{ __('You are paired') }}
                    </div>
                </div>

                <form wire:submit="updateTimezone" class="space-y-4">
                    <flux:select wire:model="timezone" :label="__('Shared timezone')">
                        @foreach ($this->timezones() as $value => $label)
                            <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button type="submit" variant="primary">{{ __('Save timezone') }}</flux:button>
                </form>
            @else
                <livewire:relationship-invitation />
            @endif
        </div>
    </x-pages::settings.layout>
</section>
