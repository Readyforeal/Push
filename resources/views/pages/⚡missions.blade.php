<?php

use App\Enums\SecretMissionStatus;
use App\Models\Relationship;
use App\Models\SecretMission;
use App\Models\SecretMissionPrompt;
use App\Models\User;
use App\Services\SecretMissionManager;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Secret missions')] class extends Component
{
    use WithPagination;

    public string $missionBody = '';

    public string $bulkMissions = '';

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function addMission(SecretMissionManager $manager): void
    {
        $validated = $this->validate([
            'missionBody' => ['required', 'string', 'max:5000'],
        ]);

        if (! $this->relationship) {
            return;
        }

        $manager->addPrompt($this->user(), $this->relationship, $validated['missionBody']);
        $this->reset('missionBody');
        unset($this->ownMissionCount, $this->ownPrompts);
        Flux::modal('add-secret-mission')->close();
        Flux::toast(variant: 'success', text: __('Mission added to your private list.'));
    }

    public function importMissions(SecretMissionManager $manager): void
    {
        $validated = $this->validate([
            'bulkMissions' => ['required', 'string', 'max:2000000'],
        ]);

        if (! $this->relationship) {
            return;
        }

        try {
            $count = $manager->importPrompts($this->user(), $this->relationship, $validated['bulkMissions']);
        } catch (DomainException $exception) {
            $this->addError('bulkMissions', $exception->getMessage());

            return;
        }

        $this->reset('bulkMissions');
        unset($this->ownMissionCount, $this->ownPrompts);
        Flux::modal('import-secret-missions')->close();
        Flux::toast(variant: 'success', text: trans_choice(':count mission imported|:count missions imported', $count, ['count' => $count]));
    }

    public function removeMissionPrompt(int $promptId, SecretMissionManager $manager): void
    {
        if (! $this->relationship) {
            return;
        }

        try {
            $manager->removePrompt(
                $this->user(),
                $this->relationship,
                SecretMissionPrompt::query()->findOrFail($promptId),
            );
        } catch (DomainException $exception) {
            $this->addError('mission', $exception->getMessage());

            return;
        }

        unset($this->ownMissionCount, $this->ownPrompts);
        Flux::toast(variant: 'success', text: __('Mission removed from your list.'));
    }

    public function claimMission(SecretMissionManager $manager): void
    {
        if (! $this->relationship) {
            return;
        }

        try {
            $manager->claim($this->user(), $this->relationship);
        } catch (DomainException $exception) {
            $this->addError('mission', $exception->getMessage());

            return;
        }

        unset($this->activeMission, $this->recentMissions);
        Flux::toast(variant: 'success', text: __('Your secret mission is ready.'));
    }

    public function completeMission(int $missionId, SecretMissionManager $manager): void
    {
        if (! $this->relationship) {
            return;
        }

        try {
            $manager->complete(
                $this->user(),
                $this->relationship,
                SecretMission::query()->findOrFail($missionId),
            );
        } catch (DomainException $exception) {
            $this->addError('mission', $exception->getMessage());

            return;
        }

        unset($this->activeMission, $this->recentMissions);
        Flux::toast(variant: 'success', text: __('Mission complete. Your partner has been notified.'));
    }

    #[Computed]
    public function relationship(): ?Relationship
    {
        return $this->user()->relationships()->first();
    }

    #[Computed]
    public function activeMission(): ?SecretMission
    {
        return $this->relationship?->secretMissions()
            ->where('assignee_user_id', $this->user()->id)
            ->where('status', SecretMissionStatus::Active)
            ->with('beneficiary')
            ->first();
    }

    #[Computed]
    public function ownMissionCount(): int
    {
        return $this->relationship?->secretMissionPrompts()
            ->where('beneficiary_user_id', $this->user()->id)
            ->where('active', true)
            ->count() ?? 0;
    }

    #[Computed]
    public function ownPrompts(): LengthAwarePaginator
    {
        $relationshipId = $this->relationship?->id ?? 0;

        return SecretMissionPrompt::query()
            ->where('relationship_id', $relationshipId)
            ->where('beneficiary_user_id', $this->user()->id)
            ->where('active', true)
            ->when($this->search !== '', fn ($query) => $query->where('body', 'like', "%{$this->search}%"))
            ->latest('id')
            ->paginate(20);
    }

    /** @return Collection<int, SecretMission> */
    #[Computed]
    public function recentMissions(): Collection
    {
        return $this->relationship?->secretMissions()
            ->where('assignee_user_id', $this->user()->id)
            ->where('status', SecretMissionStatus::Completed)
            ->with('beneficiary')
            ->latest('completed_at')
            ->limit(8)
            ->get() ?? new Collection;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}; ?>

<div class="home-shell mx-auto flex w-full max-w-3xl flex-col gap-8 pb-6 sm:pt-5">
    <header class="px-1">
        <div class="mb-3 flex items-center gap-2.5 text-xs font-semibold uppercase tracking-[0.16em] text-violet-600 dark:text-violet-300">
            <span class="size-1.5 rounded-full bg-violet-500 shadow-[0_0_0_4px_rgba(139,92,246,0.12)]"></span>
            <span>{{ __('Just between you') }}</span>
        </div>
        <h1 class="text-[2.15rem] font-semibold leading-none tracking-[-0.04em] text-zinc-950 sm:text-5xl dark:text-white">{{ __('Secret missions') }}</h1>
        <p class="mt-3 max-w-xl text-base leading-7 text-zinc-500 sm:text-lg dark:text-zinc-400">
            {{ __('Choose a thoughtful act for your partner, without knowing what you will get until you commit.') }}
        </p>
    </header>

    @if (! $this->relationship)
        <div class="prompt-surface-muted flex min-h-64 items-center justify-center p-8 text-center">
            <div>
                <flux:icon.heart class="mx-auto size-7 text-zinc-400" />
                <flux:heading class="mt-4">{{ __('Pair with your partner first') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Secret missions become available once you are connected.') }}</flux:text>
            </div>
        </div>
    @else
        <section>
            @if ($this->activeMission)
                <div class="relative overflow-hidden rounded-[1.75rem] bg-gradient-to-br from-violet-500 via-violet-600 to-indigo-800 p-6 text-white shadow-[0_22px_60px_rgba(157,23,77,0.28)] sm:p-8">
                    <div class="absolute -right-16 -top-20 size-52 rounded-full bg-white/15 blur-3xl"></div>
                    <div class="relative">
                        <div class="flex items-start justify-between gap-4">
                            <span class="rounded-full bg-black/15 px-3 py-1.5 text-[0.6875rem] font-semibold uppercase tracking-[0.14em] ring-1 ring-white/20 backdrop-blur-md">{{ __('Your active mission') }}</span>
                            <flux:icon.gift class="size-6 text-white/80" />
                        </div>
                        <p class="mt-8 max-w-2xl text-2xl font-semibold leading-9 tracking-[-0.025em] sm:text-3xl sm:leading-10">{{ $this->activeMission->body }}</p>
                        <p class="mt-4 text-sm text-white/70">{{ __('For :name · accepted :time', ['name' => $this->activeMission->beneficiary->name, 'time' => $this->activeMission->accepted_at->diffForHumans()]) }}</p>
                        <div class="mt-7 rounded-2xl bg-black/10 p-4 ring-1 ring-white/15 backdrop-blur-sm">
                            <p class="text-sm leading-6 text-white/80">{{ __('This mission stays with you until it is done. Your partner cannot see which mission you drew.') }}</p>
                            <flux:button type="button" variant="filled" class="mt-4 w-full justify-center !bg-white !text-violet-700 hover:!bg-violet-50" icon="check" wire:click="completeMission({{ $this->activeMission->id }})" wire:confirm="{{ __('Mark this secret mission complete?') }}">
                                {{ __('Mark mission complete') }}
                            </flux:button>
                        </div>
                    </div>
                </div>
            @else
                <div class="prompt-surface p-6 sm:p-8">
                    <div class="flex size-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-600 ring-1 ring-violet-100 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/15">
                        <flux:icon.gift class="size-5" />
                    </div>
                    <flux:heading size="xl" class="mt-5 tracking-tight">{{ __('Ready for a mission?') }}</flux:heading>
                    <flux:text class="mt-2 max-w-xl leading-6">{{ __('One random request from your partner’s private list will be revealed. Once you accept it, you cannot draw another until you mark it done.') }}</flux:text>
                    <flux:error name="mission" class="mt-4" />
                    <flux:button type="button" variant="primary" icon="gift" class="mt-5 w-full justify-center" wire:click="claimMission" wire:confirm="{{ __('Take a secret mission? You will keep the mission you draw until it is complete.') }}">
                        {{ __('Take a secret mission') }}
                    </flux:button>
                </div>
            @endif
        </section>

        <section class="prompt-surface p-5 sm:p-7">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-violet-600 dark:text-violet-300">
                        <flux:icon.lock-closed class="size-3.5" />
                        <span>{{ __('Your private list') }}</span>
                    </div>
                    <flux:heading size="lg" class="mt-2 tracking-tight">{{ trans_choice(':count mission for your partner|:count missions for your partner', $this->ownMissionCount, ['count' => $this->ownMissionCount]) }}</flux:heading>
                    <flux:text class="mt-1 max-w-xl leading-6">{{ __('Only you can browse this list. Your partner sees a single random mission only after choosing to take one.') }}</flux:text>
                </div>
                <div class="flex gap-2">
                    <flux:modal.trigger name="import-secret-missions">
                        <flux:button type="button" size="sm" variant="ghost" icon="arrow-up-tray">{{ __('Import') }}</flux:button>
                    </flux:modal.trigger>
                    <flux:modal.trigger name="add-secret-mission">
                        <flux:button type="button" size="sm" variant="primary" icon="plus">{{ __('Add') }}</flux:button>
                    </flux:modal.trigger>
                </div>
            </div>

            @if ($this->ownMissionCount > 0)
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" class="mt-5" :placeholder="__('Search your missions…')" />
                <div class="mt-4 space-y-2">
                    @foreach ($this->ownPrompts as $prompt)
                        <div wire:key="secret-mission-prompt-{{ $prompt->id }}" class="flex items-start gap-3 rounded-2xl bg-zinc-50/75 p-4 ring-1 ring-zinc-200/70 dark:bg-white/[0.035] dark:ring-white/8">
                            <div class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300">
                                <flux:icon.heart class="size-3.5" />
                            </div>
                            <p class="min-w-0 flex-1 text-sm leading-6 text-zinc-700 dark:text-zinc-200">{{ $prompt->body }}</p>
                            <flux:button type="button" size="sm" variant="ghost" icon="trash" aria-label="{{ __('Remove mission') }}" wire:click="removeMissionPrompt({{ $prompt->id }})" wire:confirm="{{ __('Remove this mission from your private list? Existing assignments will not change.') }}" />
                        </div>
                    @endforeach
                </div>

                @if ($this->ownPrompts->hasPages())
                    <div class="mt-5">{{ $this->ownPrompts->links() }}</div>
                @endif
            @else
                <div class="mt-5 rounded-2xl border border-dashed border-zinc-300 p-6 text-center dark:border-white/15">
                    <flux:text>{{ __('Add thoughtful things you would genuinely enjoy your partner doing for you.') }}</flux:text>
                </div>
            @endif
        </section>

        @if ($this->recentMissions->isNotEmpty())
            <section>
                <div class="flex items-center justify-between px-1">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500">{{ __('Missions you completed') }}</p>
                    <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $this->recentMissions->count() }}</span>
                </div>
                <div class="mt-3 space-y-2" data-page-stagger>
                    @foreach ($this->recentMissions as $mission)
                        <div class="prompt-surface-muted flex items-start gap-3 p-4" wire:key="completed-secret-mission-{{ $mission->id }}">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300">
                                <flux:icon.check class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm leading-6 text-zinc-700 dark:text-zinc-200">{{ $mission->body }}</p>
                                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ __('For :name · :time', ['name' => $mission->beneficiary->name, 'time' => $mission->completed_at?->diffForHumans()]) }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <flux:modal name="add-secret-mission" :show="$errors->has('missionBody')" focusable class="max-w-lg">
            <form wire:submit="addMission" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Add a secret mission') }}</flux:heading>
                    <flux:subheading>{{ __('Write something you would love your partner to do for you.') }}</flux:subheading>
                </div>
                <flux:textarea wire:model="missionBody" :label="__('Mission')" rows="5" maxlength="5000" placeholder="Bring me my favorite drink without being asked…" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Add mission') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="import-secret-missions" :show="$errors->has('bulkMissions')" focusable class="max-w-2xl">
            <form wire:submit="importMissions" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Import secret missions') }}</flux:heading>
                    <flux:subheading>{{ __('Paste one mission per line. Large lists are welcome.') }}</flux:subheading>
                </div>
                <div class="rounded-xl bg-zinc-100 p-4 text-sm text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    {{ __('Every non-empty line becomes a separate mission. Repeats are allowed because missions remain reusable after completion.') }}
                </div>
                <flux:textarea wire:model="bulkMissions" :label="__('Missions')" rows="14" maxlength="2000000" class="font-mono text-sm" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="arrow-up-tray">{{ __('Import missions') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
