<?php

use App\Models\RelationshipInvitation;
use App\Models\User;
use App\Services\RelationshipPairing;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Join your partner')] class extends Component {
    #[Locked]
    public string $token = '';

    public function mount(string $token, RelationshipPairing $pairing): void
    {
        abort_unless($pairing->findInvitation($token), 404);

        $this->token = $token;
    }

    public function accept(RelationshipPairing $pairing): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        try {
            $pairing->accept($user, $this->token);
        } catch (DomainException $exception) {
            $this->addError('invitation', $exception->getMessage());

            return;
        }

        $this->redirectRoute('dashboard', navigate: true);
    }

    #[Computed]
    public function invitation(): RelationshipInvitation
    {
        return app(RelationshipPairing::class)->findInvitation($this->token) ?? abort(404);
    }
}; ?>

<div class="mx-auto flex w-full max-w-lg flex-col gap-6 py-8">
    <div class="text-center">
        <flux:heading size="xl">{{ __('Join :name', ['name' => $this->invitation->inviter->name]) }}</flux:heading>
        <flux:text class="mt-2">{{ __('Build a shared space for your daily prompts and results.') }}</flux:text>
    </div>

    <div class="app-glass-card rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm backdrop-blur-xl dark:border-zinc-700 dark:bg-zinc-900">
        @if ($this->invitation->isPending())
            <div class="flex items-center gap-3">
                <flux:avatar circle :name="$this->invitation->inviter->name" :initials="$this->invitation->inviter->initials()" />
                <div>
                    <flux:heading>{{ $this->invitation->inviter->name }}</flux:heading>
                    <flux:text>{{ __('invited :email', ['email' => $this->invitation->email]) }}</flux:text>
                </div>
            </div>

            <flux:error name="invitation" class="mt-4" />

            <flux:button wire:click="accept" variant="primary" class="mt-6 w-full" icon="heart">
                {{ __('Accept invitation') }}
            </flux:button>
        @else
            <flux:callout variant="warning" icon="clock" :heading="__('This invitation is no longer available.')" />
        @endif
    </div>
</div>
