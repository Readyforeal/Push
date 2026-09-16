<?php

use App\Models\Relationship;
use App\Models\RelationshipInvitation;
use App\Models\User;
use App\Services\RelationshipPairing;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $email = '';
    public string $timezone = 'UTC';
    public ?string $inviteUrl = null;

    public function mount(): void
    {
        $this->timezone = $this->relationship?->timezone ?? config('app.timezone', 'UTC');
    }

    public function createInvitation(RelationshipPairing $pairing): void
    {
        $validated = $this->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'timezone' => ['required', 'timezone'],
        ]);

        try {
            $created = $pairing->invite($this->user(), $validated['email'], $validated['timezone']);
        } catch (DomainException $exception) {
            $this->addError('email', $exception->getMessage());

            return;
        }

        $this->inviteUrl = route('invitations.accept', $created->token);
        $this->email = '';
        unset($this->relationship, $this->pendingInvitation);
        Flux::modal('create-relationship-invitation')->close();
        Flux::toast(variant: 'success', text: __('Invitation created.'));
    }

    public function cancelInvitation(RelationshipPairing $pairing): void
    {
        $invitation = $this->pendingInvitation;

        if (! $invitation) {
            return;
        }

        try {
            $pairing->cancel($this->user(), $invitation);
        } catch (DomainException $exception) {
            $this->addError('email', $exception->getMessage());

            return;
        }

        $this->inviteUrl = null;
        unset($this->relationship, $this->pendingInvitation);
        Flux::toast(variant: 'success', text: __('Invitation canceled.'));
    }

    public function acceptIncomingInvitation(RelationshipPairing $pairing): void
    {
        $invitation = $this->incomingInvitation;

        if (! $invitation?->token) {
            $this->addError('invitation', __('This invitation can only be accepted from its private link.'));

            return;
        }

        try {
            $pairing->accept($this->user(), $invitation->token);
        } catch (DomainException $exception) {
            $this->addError('invitation', $exception->getMessage());

            return;
        }

        unset($this->relationship, $this->pendingInvitation, $this->incomingInvitation);
        $this->redirectRoute('dashboard', navigate: true);
    }

    #[Computed]
    public function relationship(): ?Relationship
    {
        return $this->user()->relationships()
            ->with(['invitations' => fn ($query) => $query->latest()])
            ->first();
    }

    #[Computed]
    public function pendingInvitation(): ?RelationshipInvitation
    {
        return $this->relationship?->invitations->first(fn (RelationshipInvitation $invitation) => $invitation->isPending());
    }

    #[Computed]
    public function incomingInvitation(): ?RelationshipInvitation
    {
        return RelationshipInvitation::query()
            ->with('inviter')
            ->where('email', Str::lower($this->user()->email))
            ->whereNull('accepted_at')
            ->whereNull('canceled_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
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

@php
    $shareUrl = $inviteUrl
        ?? ($this->pendingInvitation?->token
            ? route('invitations.accept', $this->pendingInvitation->token)
            : null);
@endphp

<div class="space-y-4">
    @if ($this->incomingInvitation)
        <section class="prompt-surface p-6 sm:p-8">
            <div class="flex items-center gap-3">
                <flux:avatar
                    :name="$this->incomingInvitation->inviter->name"
                    :initials="$this->incomingInvitation->inviter->initials()"
                    size="lg"
                />
                <div>
                    <flux:heading size="xl" class="tracking-tight">{{ __('Join :name', ['name' => $this->incomingInvitation->inviter->name]) }}</flux:heading>
                    <flux:text>{{ __('You have a pending partner invitation.') }}</flux:text>
                </div>
            </div>

            <flux:error name="invitation" class="mt-4" />

            <flux:button wire:click="acceptIncomingInvitation" variant="primary" icon="heart" class="mt-5">
                {{ __('Accept invitation') }}
            </flux:button>
        </section>
    @elseif ($this->pendingInvitation)
        <section class="prompt-surface p-6 sm:p-8">
            <div class="flex items-start gap-4">
                <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-pink-50 text-pink-500 dark:bg-pink-500/15 dark:text-pink-300">
                    <flux:icon.paper-airplane class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <flux:heading size="lg" class="tracking-tight">{{ __('Invitation pending') }}</flux:heading>
                    <flux:text class="mt-1 break-words">
                        {{ __('Waiting for :email to join you.', ['email' => $this->pendingInvitation->email]) }}
                    </flux:text>
                    <p class="prompt-kicker mt-3">
                        {{ __('Expires :date', ['date' => $this->pendingInvitation->expires_at->diffForHumans()]) }}
                    </p>
                </div>
            </div>
            <flux:button wire:click="cancelInvitation" variant="ghost" class="mt-5">
                {{ __('Cancel invitation') }}
            </flux:button>
        </section>
    @else
        <section class="prompt-surface relative p-7 sm:p-9">
            <div class="pointer-events-none absolute -right-16 -top-20 size-52 rounded-full bg-pink-400/10 blur-3xl"></div>
            <div class="relative flex size-12 items-center justify-center rounded-full bg-pink-50 text-pink-500 dark:bg-pink-500/15 dark:text-pink-300">
                <flux:icon.heart class="size-5" />
            </div>
            <flux:heading size="xl" class="relative mt-6 tracking-tight">{{ __('Connect with your partner') }}</flux:heading>
            <flux:text class="relative mt-2 max-w-md leading-6">{{ __('Create a private invitation link to begin your shared space.') }}</flux:text>

            <flux:modal.trigger name="create-relationship-invitation">
                <flux:button variant="primary" icon="user-plus" class="relative mt-6" data-test="open-invitation-modal">
                    {{ __('Invite your partner') }}
                </flux:button>
            </flux:modal.trigger>
        </section>

        <flux:modal
            name="create-relationship-invitation"
            :show="$errors->has('email') || $errors->has('timezone')"
            focusable
            class="max-w-lg"
        >
            <form
                wire:submit="createInvitation"
                class="space-y-6"
                x-data
                x-init="if ($wire.timezone === 'UTC') $wire.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone"
            >
                <div>
                    <flux:heading size="lg">{{ __('Invite your partner') }}</flux:heading>
                    <flux:subheading>{{ __('Choose the email they will use to sign in and your shared timezone.') }}</flux:subheading>
                </div>

                <flux:input
                    wire:model="email"
                    :label="__('Partner email')"
                    type="email"
                    placeholder="partner@example.com"
                    required
                    autofocus
                />
                <flux:select wire:model="timezone" :label="__('Shared timezone')">
                    @foreach ($this->timezones() as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="user-plus">
                        {{ __('Create invitation') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($shareUrl)
        <div x-data="{ copied: false }" class="app-glass-card rounded-2xl bg-white/60 p-5 ring-1 ring-zinc-200/70 backdrop-blur-xl dark:bg-white/5 dark:ring-white/10">
            <flux:heading>{{ __('Share this private link') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Your partner must sign in using the invited email address.') }}</flux:text>
            <div class="mt-3 flex gap-2">
                <flux:input value="{{ $shareUrl }}" readonly class="min-w-0 flex-1" />
                <flux:button
                    type="button"
                    icon="clipboard"
                    x-on:click="navigator.clipboard.writeText(@js($shareUrl)); copied = true"
                    x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"
                />
            </div>
        </div>
    @endif
</div>
