<?php

use App\Domain\Stocks\Models\Company;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $title = '';

    public string $name = '';

    public string $symbol = '';

    public function mount(): void
    {
        $this->title = __('Companies');
    }

    public function updatedSymbol(string $value): void
    {
        $this->symbol = Str::upper($value);
    }

    #[Computed]
    public function companies(): Collection
    {
        return Company::query()
            ->withCount(['stockImports', 'stockPrices'])
            ->orderBy('name')
            ->get();
    }

    public function createCompany(): void
    {
        $this->symbol = Str::upper($this->symbol);

        $validated = $this->validate(
            [
                'name' => ['required', 'string', 'max:255'],
                'symbol' => [
                    'required',
                    'string',
                    'max:32',
                    'regex:/^[A-Z0-9\-.]+$/',
                    Rule::unique(Company::class, 'symbol'),
                ],
            ],
            [],
            [
                'symbol' => __('symbol'),
            ],
        );

        DB::transaction(function () use ($validated): void {
            Company::query()->create([
                'name' => $validated['name'],
                'symbol' => $validated['symbol'],
                'slug' => $this->generateUniqueSlug($validated['name']),
            ]);
        });

        $this->reset(['name', 'symbol']);
        $this->resetErrorBag();

        $this->dispatch('company-created');
    }

    private function generateUniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = Str::lower(Str::ulid());
        }

        $slug = $baseSlug;
        $suffix = 2;

        while (Company::query()->where('slug', $slug)->exists()) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            $suffix++;
        }

        return $slug;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">{{ __('Add Company') }}</flux:heading>

            <flux:badge icon="plus-circle" variant="solid" color="indigo">
                {{ __('New') }}
            </flux:badge>
        </div>

        <flux:text class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">
            {{ __('Create a company to start uploading its historical stock prices.') }}
        </flux:text>

        <form wire:submit="createCompany" class="mt-6 grid gap-4 md:grid-cols-[2fr,1fr] md:items-start">
            <flux:input
                wire:model="name"
                :label="__('Company name')"
                placeholder="{{ __('e.g. Acme Corporation') }}"
                required
            />

            <div class="grid gap-2">
                <flux:input
                    wire:model="symbol"
                    :label="__('Ticker symbol')"
                    placeholder="{{ __('e.g. ACME') }}"
                    required
                />

                <flux:text size="sm" class="text-neutral-500 dark:text-neutral-400">
                    {{ __('Only uppercase letters, numbers, hyphen, or dot.') }}
                </flux:text>
            </div>

            <flux:button
                type="submit"
                variant="primary"
                class="md:col-span-2 md:justify-self-start"
                wire:loading.attr="disabled"
            >
                <span wire:loading.remove>{{ __('Create company') }}</span>
                <span wire:loading>{{ __('Saving...') }}</span>
            </flux:button>

            <x-action-message on="company-created" class="text-sm font-medium text-green-600 dark:text-green-400 md:col-span-2">
                {{ __('Company created successfully.') }}
            </x-action-message>
        </form>
    </div>

    <div class="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">{{ __('Companies') }}</flux:heading>

            <flux:badge icon="list-bullet" color="zinc">
                {{ $this->companies->count() }} {{ __('total') }}
            </flux:badge>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                        <th class="py-2">{{ __('Name') }}</th>
                        <th class="py-2">{{ __('Symbol') }}</th>
                        <th class="py-2">{{ __('Slug') }}</th>
                        <th class="py-2 text-right">{{ __('Imports') }}</th>
                        <th class="py-2 text-right">{{ __('Prices') }}</th>
                        <th class="py-2 text-right">{{ __('Created') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($this->companies as $company)
                        <tr class="text-neutral-700 dark:text-neutral-200">
                            <td class="py-3">
                                <div class="font-medium">{{ $company->name }}</div>
                            </td>
                            <td class="py-3">
                                <flux:badge variant="solid" color="blue">{{ $company->symbol }}</flux:badge>
                            </td>
                            <td class="py-3">
                                <span class="break-all text-xs text-neutral-500 dark:text-neutral-400">{{ $company->slug }}</span>
                            </td>
                            <td class="py-3 text-right">
                                {{ number_format($company->stock_imports_count) }}
                            </td>
                            <td class="py-3 text-right">
                                {{ number_format($company->stock_prices_count) }}
                            </td>
                            <td class="py-3 text-right text-xs text-neutral-500 dark:text-neutral-400">
                                {{ optional($company->created_at)->diffForHumans() }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-neutral-500 dark:text-neutral-400">
                                {{ __('No companies yet. Add your first company to enable imports.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
