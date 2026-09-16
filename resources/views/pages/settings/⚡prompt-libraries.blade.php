<?php

use App\Enums\PromptRoundKind;
use App\Models\PromptLibrary;
use App\Models\PromptTemplate;
use App\Models\Relationship;
use App\Models\User;
use App\Services\PromptLibraryManager;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Prompt libraries')] class extends Component
{
    use WithPagination;

    public ?int $selectedLibraryId = null;

    public string $search = '';

    public string $libraryName = '';

    public string $libraryDescription = '';

    public string $libraryKind = 'shared_question';

    public string $primaryPrompt = '';

    public string $secondaryPrompt = '';

    public string $topics = '';

    public string $bulkPrompts = '';

    public ?int $primaryAssigneeId = null;

    public function mount(): void
    {
        $this->selectedLibraryId = $this->libraries->first()?->id;
        $this->resetDraftAssignment();
    }

    public function updatedSelectedLibraryId(): void
    {
        $this->resetPage();
        $this->reset('search');
        $this->resetDraftAssignment();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createLibrary(PromptLibraryManager $manager): void
    {
        $validated = $this->validate([
            'libraryName' => ['required', 'string', 'max:100'],
            'libraryDescription' => ['nullable', 'string', 'max:500'],
            'libraryKind' => ['required', Rule::enum(PromptRoundKind::class)],
        ]);
        $relationship = $this->relationship;

        if (! $relationship) {
            return;
        }

        $library = $manager->createLibrary(
            $this->user(),
            $relationship,
            $validated['libraryName'],
            PromptRoundKind::from($validated['libraryKind']),
            $validated['libraryDescription'],
        );

        $this->reset('libraryName', 'libraryDescription');
        $this->libraryKind = PromptRoundKind::SharedQuestion->value;
        unset($this->libraries, $this->selectedLibrary);
        $this->selectedLibraryId = $library->id;
        Flux::modal('create-prompt-library')->close();
        Flux::toast(variant: 'success', text: __('Library created.'));
    }

    public function addPrompt(PromptLibraryManager $manager): void
    {
        $this->validate([
            'primaryPrompt' => ['required', 'string', 'max:5000'],
            'secondaryPrompt' => ['nullable', 'string', 'max:5000'],
            'topics' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $this->relationship || ! $this->selectedLibrary) {
            return;
        }

        try {
            $manager->addPrompt(
                $this->user(),
                $this->relationship,
                $this->selectedLibrary,
                $this->primaryPrompt,
                $this->secondaryPrompt,
                $this->parseTopics($this->topics),
                $this->primaryAssigneeId,
            );
        } catch (DomainException $exception) {
            $this->addError('secondaryPrompt', $exception->getMessage());

            return;
        }

        $this->reset('primaryPrompt', 'secondaryPrompt', 'topics');
        unset($this->libraries, $this->prompts);
        Flux::modal('add-library-prompt')->close();
        Flux::toast(variant: 'success', text: __('Prompt added.'));
    }

    public function importPrompts(PromptLibraryManager $manager): void
    {
        $this->validate(['bulkPrompts' => ['required', 'string', 'max:2000000']]);

        if (! $this->relationship || ! $this->selectedLibrary) {
            return;
        }

        try {
            $count = $manager->importPrompts(
                $this->user(),
                $this->relationship,
                $this->selectedLibrary,
                $this->bulkPrompts,
                $this->primaryAssigneeId,
            );
        } catch (DomainException $exception) {
            $this->addError('bulkPrompts', $exception->getMessage());

            return;
        }

        $this->reset('bulkPrompts');
        unset($this->libraries, $this->prompts);
        Flux::modal('bulk-import-prompts')->close();
        Flux::toast(variant: 'success', text: trans_choice(':count prompt imported|:count prompts imported', $count, ['count' => $count]));
    }

    public function swapDraftAssignments(): void
    {
        $primary = $this->primaryPerson();
        $secondary = $this->secondaryPerson();

        if ($primary && $secondary) {
            $this->primaryAssigneeId = $secondary->id;
        }
    }

    public function swapPromptAssignment(int $promptId, PromptLibraryManager $manager): void
    {
        if (! $this->relationship) {
            return;
        }

        $manager->swapPromptAssignment(
            $this->user(),
            $this->relationship,
            PromptTemplate::query()->findOrFail($promptId),
        );

        unset($this->prompts);
        Flux::toast(variant: 'success', text: __('Prompt roles swapped.'));
    }

    public function removePrompt(int $promptId, PromptLibraryManager $manager): void
    {
        if (! $this->relationship) {
            return;
        }

        $manager->removePrompt(
            $this->user(),
            $this->relationship,
            PromptTemplate::query()->findOrFail($promptId),
        );

        unset($this->libraries, $this->prompts);
        Flux::toast(variant: 'success', text: __('Prompt removed.'));
    }

    public function removeSelectedLibrary(PromptLibraryManager $manager): void
    {
        if (! $this->relationship || ! $this->selectedLibrary) {
            return;
        }

        $manager->removeLibrary($this->user(), $this->relationship, $this->selectedLibrary);
        $this->selectedLibraryId = null;
        unset($this->libraries, $this->selectedLibrary, $this->prompts);
        $this->selectedLibraryId = $this->libraries->first()?->id;
        Flux::toast(variant: 'success', text: __('Library removed.'));
    }

    #[Computed]
    public function relationship(): ?Relationship
    {
        return $this->user()->relationships()->first();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function partners(): Collection
    {
        if (! $this->relationship) {
            return new Collection;
        }

        return $this->relationship->members()
            ->orderBy('relationship_members.id')
            ->get();
    }

    /** @return Collection<int, PromptLibrary> */
    #[Computed]
    public function libraries(): Collection
    {
        $relationshipId = $this->relationship?->id;

        return PromptLibrary::query()
            ->where('active', true)
            ->where(function ($query) use ($relationshipId): void {
                $query->whereNull('relationship_id')
                    ->when($relationshipId, fn ($query) => $query->orWhere('relationship_id', $relationshipId));
            })
            ->withCount(['prompts' => function ($query) use ($relationshipId): void {
                $query->where('active', true)
                    ->where(function ($query) use ($relationshipId): void {
                        $query->whereNull('relationship_id')
                            ->when($relationshipId, fn ($query) => $query->orWhere('relationship_id', $relationshipId));
                    });
            }])
            ->orderByRaw('relationship_id IS NOT NULL')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedLibrary(): ?PromptLibrary
    {
        return $this->libraries->firstWhere('id', $this->selectedLibraryId);
    }

    /** @return LengthAwarePaginator<PromptTemplate> */
    #[Computed]
    public function prompts(): LengthAwarePaginator
    {
        $relationshipId = $this->relationship?->id;

        return PromptTemplate::query()
            ->where('prompt_library_id', $this->selectedLibraryId)
            ->where('active', true)
            ->where(function ($query) use ($relationshipId): void {
                $query->whereNull('relationship_id')
                    ->when($relationshipId, fn ($query) => $query->orWhere('relationship_id', $relationshipId));
            })
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($query): void {
                    $query->where('primary_prompt', 'like', "%{$this->search}%")
                        ->orWhere('secondary_prompt', 'like', "%{$this->search}%")
                        ->orWhere('topics', 'like', "%{$this->search}%");
                });
            })
            ->orderBy('position')
            ->orderBy('id')
            ->paginate(20);
    }

    public function kindLabel(PromptRoundKind $kind): string
    {
        return match ($kind) {
            PromptRoundKind::SharedQuestion => __('Shared question'),
            PromptRoundKind::UniqueQuestions => __('Different questions'),
            PromptRoundKind::PhotoPicker => __('Photo favorite'),
            PromptRoundKind::PhotoRequest => __('Photo request'),
        };
    }

    public function primaryLabel(PromptRoundKind $kind, ?int $primaryUserId = null): string
    {
        $primaryName = $this->primaryPerson($primaryUserId)?->firstName() ?? __('Partner one');
        $bothNames = $this->partnerNames();

        return match ($kind) {
            PromptRoundKind::SharedQuestion => __('Question for :names', ['names' => $bothNames]),
            PromptRoundKind::UniqueQuestions => __('Question for :name', ['name' => $primaryName]),
            PromptRoundKind::PhotoPicker => __('Photo upload prompt for :names', ['names' => $bothNames]),
            PromptRoundKind::PhotoRequest => __('Request question for :name', ['name' => $primaryName]),
        };
    }

    public function secondaryLabel(PromptRoundKind $kind, ?int $primaryUserId = null): string
    {
        $secondaryName = $this->secondaryPerson($primaryUserId)?->firstName() ?? __('Partner two');
        $bothNames = $this->partnerNames();

        return match ($kind) {
            PromptRoundKind::SharedQuestion => __('No second prompt needed'),
            PromptRoundKind::UniqueQuestions => __('Question for :name', ['name' => $secondaryName]),
            PromptRoundKind::PhotoPicker => __('Favorite-picking prompt for :names', ['names' => $bothNames]),
            PromptRoundKind::PhotoRequest => __('Photo instructions for :name', ['name' => $secondaryName]),
        };
    }

    public function usesNamedAssignments(PromptRoundKind $kind): bool
    {
        return in_array($kind, [PromptRoundKind::UniqueQuestions, PromptRoundKind::PhotoRequest], true);
    }

    public function primaryRoleDescription(PromptRoundKind $kind, ?int $primaryUserId = null): string
    {
        $name = $this->primaryPerson($primaryUserId)?->firstName() ?? __('Partner one');

        return match ($kind) {
            PromptRoundKind::UniqueQuestions => __(':name receives the first question', ['name' => $name]),
            PromptRoundKind::PhotoRequest => __(':name makes the request and picks the favorite', ['name' => $name]),
            default => $name,
        };
    }

    public function secondaryRoleDescription(PromptRoundKind $kind, ?int $primaryUserId = null): string
    {
        $name = $this->secondaryPerson($primaryUserId)?->firstName() ?? __('Partner two');

        return match ($kind) {
            PromptRoundKind::UniqueQuestions => __(':name receives the second question', ['name' => $name]),
            PromptRoundKind::PhotoRequest => __(':name takes and uploads the photos', ['name' => $name]),
            default => $name,
        };
    }

    private function primaryPerson(?int $primaryUserId = null): ?User
    {
        $primaryUserId ??= $this->primaryAssigneeId;

        return $this->partners->firstWhere('id', $primaryUserId) ?? $this->partners->first();
    }

    private function secondaryPerson(?int $primaryUserId = null): ?User
    {
        $primary = $this->primaryPerson($primaryUserId);

        return $primary
            ? $this->partners->first(fn (User $partner): bool => $partner->id !== $primary->id)
            : null;
    }

    private function partnerNames(): string
    {
        return $this->partners
            ->map(fn (User $partner): string => $partner->firstName())
            ->join(' '.__('and').' ');
    }

    private function resetDraftAssignment(): void
    {
        $this->primaryAssigneeId = $this->partners->first()?->id;
    }

    /** @return list<string> */
    private function parseTopics(string $topics): array
    {
        if (blank($topics)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $topics))));
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

    <x-pages::settings.layout
        :heading="__('Prompt libraries')"
        :subheading="__('Build curated collections for your weekly schedule to draw from')"
    >
        @if (! $this->relationship)
            <div class="app-glass-card rounded-xl border border-dashed border-zinc-300 p-6 text-center backdrop-blur-xl dark:border-zinc-700">
                <flux:heading>{{ __('Pair with your partner first') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Your shared prompt libraries will appear once you are connected.') }}</flux:text>
            </div>
        @else
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1">
                    <flux:select wire:model.live="selectedLibraryId" :label="__('Library')">
                        @foreach ($this->libraries as $library)
                            <flux:select.option :value="$library->id">
                                {{ $library->name }} — {{ $library->prompts_count }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:modal.trigger name="create-prompt-library">
                    <flux:button type="button" variant="primary" icon="plus">{{ __('New library') }}</flux:button>
                </flux:modal.trigger>
            </div>

            @if ($this->selectedLibrary)
                <div class="app-glass-card mt-6 rounded-2xl border border-zinc-200 bg-white p-5 backdrop-blur-xl dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:heading size="lg">{{ $this->selectedLibrary->name }}</flux:heading>
                                <flux:badge color="zinc">{{ $this->kindLabel($this->selectedLibrary->kind) }}</flux:badge>
                                @if ($this->selectedLibrary->relationship_id === null)
                                    <flux:badge color="blue">{{ __('Built in') }}</flux:badge>
                                @else
                                    <flux:badge color="emerald">{{ __('Your library') }}</flux:badge>
                                @endif
                            </div>
                            @if ($this->selectedLibrary->description)
                                <flux:text class="mt-2">{{ $this->selectedLibrary->description }}</flux:text>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <flux:modal.trigger name="bulk-import-prompts">
                                <flux:button type="button" size="sm" variant="ghost" icon="arrow-up-tray">{{ __('Bulk import') }}</flux:button>
                            </flux:modal.trigger>
                            <flux:modal.trigger name="add-library-prompt">
                                <flux:button type="button" size="sm" variant="primary" icon="plus">{{ __('Add prompt') }}</flux:button>
                            </flux:modal.trigger>
                        </div>
                    </div>

                    <div class="mt-5">
                        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search prompts or topics…')" />
                    </div>

                    <div class="mt-4 space-y-3" data-page-stagger>
                        @forelse ($this->prompts as $prompt)
                            @php($assignedPrimaryUserId = $prompt->primary_user_id ?? $this->partners->first()?->id)
                            <article wire:key="prompt-{{ $prompt->id }}" class="app-glass-card rounded-xl bg-zinc-50 p-4 backdrop-blur-xl dark:bg-zinc-800">
                                <div class="flex items-start gap-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="prompt-kicker mb-1">{{ $this->primaryLabel($prompt->kind, $assignedPrimaryUserId) }}</p>
                                        <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $prompt->primary_prompt }}</p>
                                        @if ($prompt->secondary_prompt)
                                            <div class="mt-3 border-s-2 border-zinc-300 ps-3 dark:border-zinc-600">
                                                <p class="prompt-kicker mb-1">{{ $this->secondaryLabel($prompt->kind, $assignedPrimaryUserId) }}</p>
                                                <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $prompt->secondary_prompt }}</p>
                                            </div>
                                        @endif
                                        @if ($prompt->topics)
                                            <div class="mt-3 flex flex-wrap gap-1.5">
                                                @foreach ($prompt->topics as $topic)
                                                    <flux:badge size="sm" color="zinc">{{ $topic }}</flux:badge>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    @if ($prompt->relationship_id === $this->relationship->id)
                                        <div class="flex shrink-0 items-center gap-1">
                                            @if ($this->usesNamedAssignments($prompt->kind))
                                                <flux:button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    icon="arrows-right-left"
                                                    aria-label="{{ __('Swap prompt roles') }}"
                                                    title="{{ __('Swap prompt roles') }}"
                                                    wire:click="swapPromptAssignment({{ $prompt->id }})"
                                                />
                                            @endif
                                            <flux:button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                icon="trash"
                                                aria-label="{{ __('Remove prompt') }}"
                                                wire:click="removePrompt({{ $prompt->id }})"
                                                wire:confirm="{{ __('Remove this prompt from the library?') }}"
                                            />
                                        </div>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="app-glass-card rounded-xl border border-dashed border-zinc-300 p-6 text-center backdrop-blur-xl dark:border-zinc-700">
                                <flux:heading>{{ __('No prompts found') }}</flux:heading>
                                <flux:text class="mt-1">{{ __('Add prompts one at a time or import a whole curated collection before scheduling this library.') }}</flux:text>
                            </div>
                        @endforelse
                    </div>

                    @if ($this->prompts->hasPages())
                        <div class="mt-5">{{ $this->prompts->links() }}</div>
                    @endif

                    @if ($this->selectedLibrary->relationship_id === $this->relationship->id)
                        <div class="mt-6 border-t border-zinc-200 pt-4 text-end dark:border-zinc-700">
                            <flux:button
                                type="button"
                                size="sm"
                                variant="danger"
                                icon="trash"
                                wire:click="removeSelectedLibrary"
                                wire:confirm="{{ __('Delete this library and all of its prompts and schedule slots?') }}"
                            >
                                {{ __('Delete library') }}
                            </flux:button>
                        </div>
                    @endif
                </div>
            @endif

            <flux:modal name="create-prompt-library" :show="$errors->has('libraryName') || $errors->has('libraryKind')" focusable class="max-w-lg">
                <form wire:submit="createLibrary" class="space-y-5">
                    <div>
                        <flux:heading size="lg">{{ __('Create a prompt library') }}</flux:heading>
                        <flux:subheading>{{ __('A library’s type determines how every prompt inside it behaves.') }}</flux:subheading>
                    </div>
                    <flux:input wire:model="libraryName" :label="__('Library name')" placeholder="Deeper conversations" />
                    <flux:textarea wire:model="libraryDescription" :label="__('Description')" rows="3" />
                    <flux:select wire:model="libraryKind" :label="__('Prompt type')">
                        @foreach (PromptRoundKind::cases() as $kind)
                            <flux:select.option :value="$kind->value">{{ $this->kindLabel($kind) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary">{{ __('Create library') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="add-library-prompt" :show="$errors->has('primaryPrompt') || $errors->has('secondaryPrompt')" focusable class="max-w-xl">
                @if ($this->selectedLibrary)
                    <form wire:submit="addPrompt" class="space-y-5">
                        <div>
                            <flux:heading size="lg">{{ __('Add to :library', ['library' => $this->selectedLibrary->name]) }}</flux:heading>
                            <flux:subheading>{{ __('This prompt will remain private to your relationship.') }}</flux:subheading>
                        </div>
                        @if ($this->usesNamedAssignments($this->selectedLibrary->kind))
                            <div class="app-glass-card rounded-2xl border border-zinc-200/80 bg-zinc-50/70 p-4 dark:border-white/8 dark:bg-zinc-800/55">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Who gets what') }}</p>
                                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $this->primaryRoleDescription($this->selectedLibrary->kind) }}</p>
                                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $this->secondaryRoleDescription($this->selectedLibrary->kind) }}</p>
                                    </div>
                                    <flux:button type="button" size="sm" variant="ghost" icon="arrows-right-left" wire:click="swapDraftAssignments">
                                        {{ __('Swap') }}
                                    </flux:button>
                                </div>
                            </div>
                        @endif
                        <flux:textarea wire:model="primaryPrompt" :label="$this->primaryLabel($this->selectedLibrary->kind)" rows="3" />
                        @if ($this->selectedLibrary->kind !== PromptRoundKind::SharedQuestion)
                            <flux:textarea wire:model="secondaryPrompt" :label="$this->secondaryLabel($this->selectedLibrary->kind)" rows="3" />
                        @endif
                        <flux:input wire:model="topics" :label="__('Topics')" :description="__('Comma separated, such as trust, intimacy, future')" />
                        <div class="flex justify-end gap-2">
                            <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                            <flux:button type="submit" variant="primary">{{ __('Add prompt') }}</flux:button>
                        </div>
                    </form>
                @endif
            </flux:modal>

            <flux:modal name="bulk-import-prompts" :show="$errors->has('bulkPrompts')" focusable class="max-w-2xl">
                @if ($this->selectedLibrary)
                    <form wire:submit="importPrompts" class="space-y-5">
                        <div>
                            <flux:heading size="lg">{{ __('Bulk import into :library', ['library' => $this->selectedLibrary->name]) }}</flux:heading>
                            <flux:subheading>{{ __('Paste one prompt per line. Thousands of lines are supported.') }}</flux:subheading>
                        </div>
                        @if ($this->usesNamedAssignments($this->selectedLibrary->kind))
                            <div class="app-glass-card rounded-2xl border border-zinc-200/80 bg-zinc-50/70 p-4 dark:border-white/8 dark:bg-zinc-800/55">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Who gets what') }}</p>
                                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $this->primaryRoleDescription($this->selectedLibrary->kind) }}</p>
                                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $this->secondaryRoleDescription($this->selectedLibrary->kind) }}</p>
                                        <p class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">{{ __('This assignment applies to every row in this import.') }}</p>
                                    </div>
                                    <flux:button type="button" size="sm" variant="ghost" icon="arrows-right-left" wire:click="swapDraftAssignments">
                                        {{ __('Swap') }}
                                    </flux:button>
                                </div>
                            </div>
                        @endif
                        <div class="app-glass-card rounded-xl bg-zinc-100 p-4 text-sm text-zinc-600 backdrop-blur-xl dark:bg-zinc-800 dark:text-zinc-300">
                            @if ($this->selectedLibrary->kind === PromptRoundKind::SharedQuestion)
                                <code>{{ __('Question text ||| ||| topic one, topic two') }}</code>
                            @else
                                <code>{{ __('First prompt ||| Second prompt ||| topic one, topic two') }}</code>
                            @endif
                            <p class="mt-2">{{ __('You may also paste tab-separated rows from a spreadsheet. Topics are optional.') }}</p>
                        </div>
                        <flux:textarea wire:model="bulkPrompts" :label="__('Prompts')" rows="14" class="font-mono text-sm" />
                        <div class="flex justify-end gap-2">
                            <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                            <flux:button type="submit" variant="primary" icon="arrow-up-tray">{{ __('Import prompts') }}</flux:button>
                        </div>
                    </form>
                @endif
            </flux:modal>
        @endif
    </x-pages::settings.layout>
</section>
