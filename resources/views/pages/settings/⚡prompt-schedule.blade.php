<?php

use App\Enums\PromptRoundKind;
use App\Models\PromptLibrary;
use App\Models\Relationship;
use App\Models\RelationshipPromptSchedule;
use App\Models\User;
use App\Services\PromptScheduleManager;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Prompt schedule')] class extends Component
{
    public int $dayOfWeek = 1;

    public ?int $promptLibraryId = null;

    public string $deliveryTime = '09:00';

    public function mount(): void
    {
        abort_unless($this->user()->is_admin, 403);
    }

    public function openAddPrompt(int $dayOfWeek): void
    {
        abort_unless(array_key_exists($dayOfWeek, $this->dayNames()), 422);

        $this->resetValidation();
        $this->dayOfWeek = $dayOfWeek;
        $this->promptLibraryId = $this->libraries->first()?->id;
        $this->deliveryTime = '09:00';
        Flux::modal('add-prompt-schedule')->show();
    }

    public function addPrompt(PromptScheduleManager $manager): void
    {
        $validated = $this->validate([
            'dayOfWeek' => ['required', 'integer', 'between:0,6'],
            'promptLibraryId' => [
                'required',
                'integer',
                Rule::exists('prompt_libraries', 'id')->where('active', true),
            ],
            'deliveryTime' => ['required', 'date_format:H:i'],
        ]);

        $relationship = $this->relationship;

        if (! $relationship) {
            return;
        }

        $library = PromptLibrary::query()->findOrFail($validated['promptLibraryId']);
        $manager->add(
            $this->user(),
            $relationship,
            $library,
            $validated['dayOfWeek'],
            $validated['deliveryTime'],
        );

        unset($this->schedules);
        Flux::modal('add-prompt-schedule')->close();
        Flux::toast(variant: 'success', text: __('Prompt added to your week.'));
    }

    public function removePrompt(int $scheduleId, PromptScheduleManager $manager): void
    {
        $relationship = $this->relationship;

        if (! $relationship) {
            return;
        }

        $schedule = RelationshipPromptSchedule::query()->findOrFail($scheduleId);
        $manager->remove($this->user(), $relationship, $schedule);

        unset($this->schedules);
        Flux::toast(variant: 'success', text: __('Prompt removed from your week.'));
    }

    #[Computed]
    public function relationship(): ?Relationship
    {
        return $this->user()->relationships()->first();
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
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, RelationshipPromptSchedule> */
    #[Computed]
    public function schedules(): Collection
    {
        if (! $this->relationship) {
            return new Collection;
        }

        return $this->relationship->promptSchedules()
            ->with(['library', 'template'])
            ->where('active', true)
            ->orderBy('day_of_week')
            ->orderBy('delivery_time')
            ->orderBy('position')
            ->get();
    }

    /** @return array<int, string> */
    public function dayNames(): array
    {
        return [
            1 => __('Monday'),
            2 => __('Tuesday'),
            3 => __('Wednesday'),
            4 => __('Thursday'),
            5 => __('Friday'),
            6 => __('Saturday'),
            0 => __('Sunday'),
        ];
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

    public function promptCount(PromptLibrary $library): int
    {
        $relationshipId = $this->relationship?->id;

        return $library->prompts()
            ->where('active', true)
            ->where(function ($query) use ($relationshipId): void {
                $query->whereNull('relationship_id')
                    ->when($relationshipId, fn ($query) => $query->orWhere('relationship_id', $relationshipId));
            })
            ->count();
    }

    public function formatTime(string $time): string
    {
        return CarbonImmutable::parse($time)->format('g:i A');
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
        :heading="__('Prompt schedule')"
        :subheading="__('Design the rhythm of your week together')"
    >
        @if (! $this->relationship)
            <div class="app-glass-card rounded-xl border border-dashed border-zinc-300 p-6 text-center backdrop-blur-xl dark:border-zinc-700">
                <flux:heading>{{ __('Pair with your partner first') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Your shared schedule will appear once you are connected.') }}</flux:text>
            </div>
        @else
            <div class="app-glass-card mb-6 rounded-xl bg-zinc-100 p-4 backdrop-blur-xl dark:bg-zinc-800">
                <div class="flex gap-3">
                    <flux:icon.information-circle class="mt-0.5 size-5 shrink-0 text-zinc-500" />
                    <flux:text>
                        {{ __('Times use :timezone. If a prompt is still waiting for you, it stays put and the next due prompt waits until you finish.', ['timezone' => str_replace('_', ' ', $this->relationship->timezone)]) }}
                    </flux:text>
                </div>
            </div>

            <div class="space-y-4" data-page-stagger>
                @foreach ($this->dayNames() as $dayNumber => $dayName)
                    @php($daySchedules = $this->schedules->where('day_of_week', $dayNumber))

                    <section class="app-glass-card rounded-2xl border border-zinc-200 bg-white p-4 backdrop-blur-xl dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex items-center justify-between gap-4">
                            <flux:heading>{{ $dayName }}</flux:heading>
                            <flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="openAddPrompt({{ $dayNumber }})">
                                {{ __('Add prompt') }}
                            </flux:button>
                        </div>

                        @if ($daySchedules->isEmpty())
                            <flux:text class="mt-3 text-sm">{{ __('No prompts scheduled.') }}</flux:text>
                        @else
                            <div class="mt-3 space-y-2">
                                @foreach ($daySchedules as $schedule)
                                    <div wire:key="schedule-{{ $schedule->id }}" class="app-glass-card flex items-start gap-3 rounded-xl bg-zinc-50 p-3 backdrop-blur-xl dark:bg-zinc-800">
                                        <div class="min-w-20 rounded-lg bg-white px-2 py-1 text-center text-sm font-medium shadow-sm dark:bg-zinc-900">
                                            {{ $this->formatTime($schedule->delivery_time) }}
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                                                {{ $this->kindLabel($schedule->library?->kind ?? $schedule->template->kind) }}
                                            </div>
                                            <p class="mt-1 text-sm font-medium text-zinc-900 dark:text-white">
                                                {{ $schedule->library?->name ?? $schedule->template->primary_prompt }}
                                            </p>
                                            @if ($schedule->library)
                                                <p class="mt-0.5 text-xs text-zinc-500">
                                                    {{ trans_choice(':count available prompt|:count available prompts', $this->promptCount($schedule->library), ['count' => $this->promptCount($schedule->library)]) }}
                                                </p>
                                            @endif
                                        </div>
                                        <flux:button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            icon="trash"
                                            aria-label="{{ __('Remove prompt') }}"
                                            wire:click="removePrompt({{ $schedule->id }})"
                                            wire:confirm="{{ __('Remove this prompt from your weekly schedule?') }}"
                                        />
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>

            @if ($this->schedules->isEmpty())
                <flux:text class="mt-5 text-sm">
                    {{ __('Until you add your first slot, the automatic daily rotation continues at 9:00 AM.') }}
                </flux:text>
            @endif

            <flux:modal name="add-prompt-schedule" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
                <form wire:submit="addPrompt" class="space-y-5">
                    <div>
                        <flux:heading size="lg">{{ __('Add a prompt to :day', ['day' => $this->dayNames()[$dayOfWeek]]) }}</flux:heading>
                        <flux:subheading>{{ __('Choose what arrives and when.') }}</flux:subheading>
                    </div>

                    <flux:select wire:model="promptLibraryId" :label="__('Prompt library')">
                        @foreach ($this->libraries as $library)
                            <flux:select.option :value="$library->id">
                                {{ $this->kindLabel($library->kind) }} — {{ $library->name }} ({{ $library->prompts_count }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:text class="text-sm">{{ __('A prompt will be chosen randomly from this library when the slot becomes due.') }}</flux:text>

                    <flux:input wire:model="deliveryTime" type="time" :label="__('Delivery time')" required />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" icon="plus">{{ __('Add to schedule') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif
    </x-pages::settings.layout>
</section>
