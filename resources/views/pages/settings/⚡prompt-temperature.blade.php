<?php

use App\Models\PromptTemplate;
use App\Models\Relationship;
use App\Models\User;
use App\Services\RelationshipTemperature;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Prompt Temperature')] class extends Component
{
    /** @var list<array{tag: string, minimum_temperature: int}> */
    public array $rules = [];

    public function mount(): void
    {
        abort_unless($this->user()->is_admin, 403);
        $this->loadRules();
    }

    public function save(): void
    {
        $this->validate([
            'rules' => ['array'],
            'rules.*.tag' => ['required', 'string', 'max:255'],
            'rules.*.minimum_temperature' => ['required', 'integer', 'between:1,10'],
        ]);

        $relationship = $this->relationship;

        if (! $relationship) {
            return;
        }

        foreach ($this->rules as $rule) {
            $tag = mb_strtolower(trim($rule['tag']));
            $minimum = (int) $rule['minimum_temperature'];

            if ($minimum === 1) {
                $relationship->promptTagRules()->where('tag', $tag)->delete();

                continue;
            }

            $relationship->promptTagRules()->updateOrCreate(
                ['tag' => $tag],
                ['minimum_temperature' => $minimum],
            );
        }

        Flux::toast(variant: 'success', text: __('Prompt temperature rules saved.'));
        $this->loadRules();
    }

    #[Computed]
    public function relationship(): ?Relationship
    {
        return $this->user()->relationships()->first();
    }

    #[Computed]
    public function currentTemperature(): int
    {
        return $this->relationship
            ? app(RelationshipTemperature::class)->current($this->relationship)
            : RelationshipTemperature::DEFAULT;
    }

    public function temperatureLabel(int $temperature): string
    {
        return match (true) {
            $temperature <= 2 => __('Running low'),
            $temperature <= 4 => __('Tender'),
            $temperature <= 6 => __('Steady'),
            $temperature <= 8 => __('Warm'),
            default => __('Glowing'),
        };
    }

    private function loadRules(): void
    {
        $relationship = $this->relationship;

        if (! $relationship) {
            $this->rules = [];

            return;
        }

        $minimums = $relationship->promptTagRules()
            ->pluck('minimum_temperature', 'tag');
        $tags = PromptTemplate::query()
            ->where(function (Builder $query) use ($relationship): void {
                $query->whereNull('relationship_id')
                    ->orWhere('relationship_id', $relationship->id);
            })
            ->get(['topics'])
            ->flatMap(fn (PromptTemplate $template): array => $template->topics ?? [])
            ->filter(fn ($tag): bool => is_string($tag) && filled($tag))
            ->map(fn (string $tag): string => mb_strtolower(trim($tag)))
            ->unique()
            ->sort()
            ->values();

        $this->rules = $tags
            ->map(fn (string $tag): array => [
                'tag' => $tag,
                'minimum_temperature' => (int) ($minimums[$tag] ?? 1),
            ])
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

    <x-pages::settings.layout
        :heading="__('Prompt temperature')"
        :subheading="__('Decide when sensitive prompt tags are comfortable to draw')"
    >
        @if (! $this->relationship)
            <div class="rounded-2xl border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-700">
                <flux:heading>{{ __('Pair with your partner first') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Temperature rules belong to your shared space.') }}</flux:text>
            </div>
        @else
            <form wire:submit="save" class="space-y-6">
                <div class="overflow-hidden rounded-[1.5rem] bg-gradient-to-br from-violet-600 to-violet-800 p-5 text-white shadow-lg shadow-violet-950/10 sm:p-6">
                    <div class="flex items-start justify-between gap-5">
                        <div>
                            <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.15em] text-violet-100/80">{{ __('Current shared temperature') }}</p>
                            <p class="mt-2 text-4xl font-semibold tracking-[-0.05em]">{{ $this->currentTemperature }}<span class="text-xl text-white/55">/10</span></p>
                            <p class="mt-1 text-sm text-white/75">{{ $this->temperatureLabel($this->currentTemperature) }}</p>
                        </div>
                        <span class="flex size-11 items-center justify-center rounded-2xl bg-white/12 ring-1 ring-white/15 backdrop-blur-md">
                            <flux:icon.adjustments-horizontal class="size-5" />
                        </span>
                    </div>

                    <p class="mt-5 max-w-xl text-sm leading-6 text-white/75">
                        {{ __('Push uses the lower of your two latest temperatures. A prompt is eligible only when every one of its tags is allowed at that temperature.') }}
                    </p>
                </div>

                @if ($rules !== [])
                    <div class="overflow-hidden rounded-[1.5rem] bg-white/58 ring-1 ring-zinc-200/70 backdrop-blur-xl dark:bg-zinc-950/42 dark:ring-white/8">
                        @foreach ($rules as $index => $rule)
                            <div class="flex flex-col gap-3 border-b border-zinc-200/60 px-4 py-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between sm:px-5 dark:border-white/7" wire:key="temperature-tag-{{ $rule['tag'] }}">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-600 dark:bg-violet-500/12 dark:text-violet-300">
                                        <flux:icon.tag class="size-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold capitalize text-zinc-950 dark:text-white">{{ $rule['tag'] }}</p>
                                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $rule['minimum_temperature'] === 1
                                                ? __('Allowed at every temperature')
                                                : __('Allowed at :temperature and above', ['temperature' => $rule['minimum_temperature']]) }}
                                        </p>
                                    </div>
                                </div>

                                <label class="flex items-center justify-between gap-3 sm:justify-end">
                                    <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Minimum') }}</span>
                                    <select wire:model.live="rules.{{ $index }}.minimum_temperature" class="min-w-24 rounded-xl border-0 bg-zinc-100 px-3 py-2 text-sm font-semibold text-zinc-900 ring-1 ring-zinc-200 focus:ring-2 focus:ring-violet-500 dark:bg-white/7 dark:text-white dark:ring-white/10">
                                        @foreach (range(1, 10) as $temperature)
                                            <option value="{{ $temperature }}">{{ $temperature === 1 ? __('Always') : $temperature.'+' }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                        @endforeach
                    </div>

                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                        {{ __('Save temperature rules') }}
                    </flux:button>
                @else
                    <div class="rounded-[1.5rem] border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-zinc-700">
                        <span class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-500 dark:bg-violet-500/10 dark:text-violet-300">
                            <flux:icon.tag class="size-5" />
                        </span>
                        <flux:heading class="mt-4">{{ __('No prompt tags yet') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Add tags to prompts in Prompt Libraries, then return here to set their minimum temperatures.') }}</flux:text>
                    </div>
                @endif

                <p class="px-1 text-xs leading-5 text-zinc-400 dark:text-zinc-500">
                    {{ __('Untagged prompts and tags set to Always remain eligible at every temperature. Existing active prompts are never replaced when a temperature changes.') }}
                </p>
            </form>
        @endif
    </x-pages::settings.layout>
</section>
