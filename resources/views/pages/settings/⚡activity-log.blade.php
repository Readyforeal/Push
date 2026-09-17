<?php

use App\Models\PageVisit;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Activity Log')] class extends Component
{
    use WithPagination;

    #[Url(as: 'user')]
    public ?int $userId = null;

    public function mount(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User && $actor->is_admin, 403);

        if ($this->userId === null) {
            $this->userId = User::query()
                ->where('id', '!=', $actor->id)
                ->oldest('id')
                ->value('id') ?? $actor->id;
        }
    }

    public function updatedUserId(): void
    {
        $this->resetPage();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function users(): Collection
    {
        return User::query()->orderBy('name')->get();
    }

    #[Computed]
    public function selectedUser(): ?User
    {
        return User::query()->find($this->userId);
    }

    /** @return LengthAwarePaginator<PageVisit> */
    #[Computed]
    public function visits(): LengthAwarePaginator
    {
        return PageVisit::query()
            ->when($this->userId, fn ($query, $userId) => $query->where('user_id', $userId))
            ->with('user')
            ->recent()
            ->paginate(30);
    }

    #[Computed]
    public function timezone(): string
    {
        $actor = Auth::user();

        return $actor instanceof User
            ? ($actor->relationships()->value('timezone') ?? config('app.timezone'))
            : config('app.timezone');
    }

    /** @return array{today: int, latest: Carbon|null} */
    #[Computed]
    public function summary(): array
    {
        $query = PageVisit::query()
            ->when($this->userId, fn ($builder, $userId) => $builder->where('user_id', $userId));
        $today = now($this->timezone)->startOfDay()->utc();
        $latest = (clone $query)->max('visited_at');

        return [
            'today' => (clone $query)->where('visited_at', '>=', $today)->count(),
            'latest' => $latest ? Carbon::parse((string) $latest) : null,
        ];
    }
}; ?>

<section class="w-full" wire:poll.15s>
    @include('partials.settings-heading')

    <x-pages::settings.layout
        :heading="__('Activity log')"
        :subheading="__('Authenticated page visits from the last 90 days')"
    >
        <div class="space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="w-full sm:max-w-xs">
                    <flux:select wire:model.live="userId" :label="__('Person')">
                        @foreach ($this->users as $user)
                            <flux:select.option :value="$user->id">
                                {{ $user->name }}{{ $user->id === auth()->id() ? ' · '.__('You') : '' }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="flex items-center gap-2 text-xs text-zinc-400 dark:text-zinc-500">
                    <span class="relative flex size-2">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-50"></span>
                        <span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span>
                    </span>
                    {{ __('Updates every 15 seconds') }}
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-2xl bg-violet-50/80 p-4 ring-1 ring-violet-100 dark:bg-violet-500/10 dark:ring-violet-400/15">
                    <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.14em] text-violet-600 dark:text-violet-300">{{ __('Today') }}</p>
                    <p class="mt-2 text-2xl font-semibold tracking-[-0.04em] text-zinc-950 dark:text-white">{{ $this->summary['today'] }}</p>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('page visits') }}</p>
                </div>

                <div class="rounded-2xl bg-zinc-100/75 p-4 ring-1 ring-zinc-200/70 dark:bg-white/[0.045] dark:ring-white/8">
                    <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-400">{{ __('Last seen') }}</p>
                    <p class="mt-2 text-sm font-semibold text-zinc-950 dark:text-white">
                        {{ $this->summary['latest']?->timezone($this->timezone)->diffForHumans() ?? __('No activity yet') }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $this->selectedUser?->firstName() }}</p>
                </div>
            </div>

            @if ($this->visits->isNotEmpty())
                <div class="overflow-hidden rounded-[1.5rem] bg-white/58 ring-1 ring-zinc-200/70 backdrop-blur-xl dark:bg-zinc-950/42 dark:ring-white/8" data-page-stagger>
                    @foreach ($this->visits as $visit)
                        @php($localTime = $visit->visited_at->timezone($this->timezone))
                        <div class="flex items-center gap-3.5 border-b border-zinc-200/60 px-4 py-3.5 last:border-b-0 sm:px-5 dark:border-white/7" wire:key="visit-{{ $visit->id }}">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-violet-100/80 text-violet-700 dark:bg-violet-500/12 dark:text-violet-300">
                                <flux:icon :name="$visit->icon()" class="size-4.5" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-zinc-950 dark:text-white">{{ $visit->label() }}</p>
                                <p class="mt-0.5 truncate text-xs text-zinc-400 dark:text-zinc-500">/{{ $visit->route_uri }}</p>
                            </div>

                            <time datetime="{{ $visit->visited_at->toIso8601String() }}" class="shrink-0 text-right">
                                <span class="block text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ $localTime->format('g:i A') }}</span>
                                <span class="mt-0.5 block text-[0.6875rem] text-zinc-400 dark:text-zinc-500">{{ $localTime->isToday() ? __('Today') : $localTime->format('M j') }}</span>
                            </time>
                        </div>
                    @endforeach
                </div>

                @if ($this->visits->hasPages())
                    <div>{{ $this->visits->links() }}</div>
                @endif
            @else
                <div class="rounded-[1.5rem] border border-dashed border-zinc-300/80 px-6 py-12 text-center dark:border-zinc-700">
                    <span class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-500 dark:bg-violet-500/10 dark:text-violet-300">
                        <flux:icon.chart-bar-square class="size-5" />
                    </span>
                    <flux:heading class="mt-4">{{ __('No page visits yet') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Activity will appear here as this person moves through the app.') }}</flux:text>
                </div>
            @endif

            <p class="px-1 text-xs leading-5 text-zinc-400 dark:text-zinc-500">
                {{ __('This records page names and visit times only. Answers, form contents, comments, IP addresses, and precise device details are not collected.') }}
            </p>
        </div>
    </x-pages::settings.layout>
</section>
