<?php

use App\Domain\Stocks\Actions\CreateStockImportFromUpload;
use App\Domain\Stocks\Actions\QueueStockImport;
use App\Domain\Stocks\Enums\StockImportStatus;
use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockImport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public string $title = '';

    public ?int $companyId = null;

    public ?TemporaryUploadedFile $upload = null;

    /**
     * @var array<int, array{value:int,label:string}>
     */
    public array $companyOptions = [];

    public function mount(): void
    {
        $this->title = __('Dashboard');

        $this->companyOptions = Company::query()
            ->orderBy('name')
            ->get(['id', 'name', 'symbol'])
            ->map(fn (Company $company): array => [
                'value' => $company->id,
                'label' => sprintf('%s (%s)', $company->name, $company->symbol),
            ])
            ->all();

        if ($this->companyId === null && $this->companyOptions !== []) {
            $this->companyId = $this->companyOptions[0]['value'];
        }
    }

    #[Computed]
    public function hasCompanies(): bool
    {
        return count($this->companyOptions) > 0;
    }

    #[Computed(cache: false)]
    public function recentImports(): Collection
    {
        return StockImport::query()
            ->with(['company:id,name,symbol'])
            ->latest('created_at')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function canQueueImport(): bool
    {
        return $this->hasCompanies
            && $this->companyId !== null
            && $this->upload !== null;
    }

    public function refreshImports(): void {}

    public function queueImport(): void
    {
        if (! $this->hasCompanies) {
            return;
        }

        $validated = $this->validate(
            [
                'companyId' => ['required', 'integer', 'exists:companies,id'],
                'upload' => ['required', 'file', 'mimes:xlsx,csv', 'max:51200'],
            ],
            [],
            [
                'companyId' => __('company'),
                'upload' => __('file'),
            ],
        );

        /** @var TemporaryUploadedFile $file */
        $file = $validated['upload'];

        try {
            $import = app(CreateStockImportFromUpload::class)->handle((int) $this->companyId, $file);
        } catch (\RuntimeException $exception) {
            Log::warning('Stock import upload failed.', [
                'company_id' => $this->companyId,
                'message' => $exception->getMessage(),
            ]);

            $this->addError('upload', __('We could not process the uploaded file. Please try again.'));

            return;
        }

        app(QueueStockImport::class)->handle($import);

        $this->reset('upload');
        $this->resetErrorBag();

        $this->dispatch('import-queued');
    }

    public function statusColor(StockImportStatus $status): string
    {
        return match ($status) {
            StockImportStatus::Completed => 'green',
            StockImportStatus::Failed => 'red',
            StockImportStatus::Processing => 'blue',
            StockImportStatus::Queued => 'amber',
            default => 'zinc',
        };
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="grid gap-6 xl:grid-cols-[2fr,3fr]">
        <div class="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Queue Stock Import') }}</flux:heading>

                <flux:badge icon="cloud-arrow-up" variant="solid" color="indigo">
                    {{ __('Upload') }}
                </flux:badge>
            </div>

            <flux:text class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">
                {{ __('Upload a historical stock price spreadsheet. The file will be queued for background processing to keep the dashboard responsive.') }}
            </flux:text>

            <div class="mt-6 space-y-6">
                @if (! $this->hasCompanies)
                    <flux:callout icon="building-storefront" variant="warning">
                        {{ __('No companies are available yet. Add a company before importing stock prices.') }}
                    </flux:callout>
                @else
                    <form wire:submit="queueImport" class="space-y-4">
                        <flux:select
                            wire:model="companyId"
                            :label="__('Company')"
                            placeholder="{{ __('Select a company') }}"
                            required
                        >
                            @foreach ($companyOptions as $option)
                                <flux:select.option :value="$option['value']">{{ $option['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <div>
                            <flux:input
                                type="file"
                                wire:model.live="upload"
                                :label="__('Spreadsheet file')"
                                accept=".xlsx,.csv"
                                required
                            />

                            <flux:text size="sm" class="mt-2 text-neutral-500 dark:text-neutral-400">
                                {{ __('Accepted formats: XLSX or CSV up to 50 MB.') }}
                            </flux:text>
                        </div>

                        <div class="flex items-center gap-3">
                            <flux:button
                                type="submit"
                                variant="primary"
                                :disabled="! $this->canQueueImport"
                                wire:loading.attr="disabled"
                                wire:target="queueImport,upload"
                            >
                                <span wire:loading.remove wire:target="queueImport,upload">
                                    {{ __('Queue import') }}
                                </span>

                                <span wire:loading wire:target="queueImport,upload">
                                    {{ __('Queuing...') }}
                                </span>
                            </flux:button>

                            <x-action-message on="import-queued" class="text-sm font-medium text-green-600 dark:text-green-400">
                                {{ __('Import queued successfully.') }}
                            </x-action-message>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <div
            class="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            wire:poll.15s="refreshImports"
        >
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Recent Imports') }}</flux:heading>

                <flux:badge icon="clock" color="zinc">
                    {{ __('Last 10') }}
                </flux:badge>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                            <th class="py-2">{{ __('Company') }}</th>
                            <th class="py-2">{{ __('Original file') }}</th>
                            <th class="py-2">{{ __('Status') }}</th>
                            <th class="py-2 text-right">{{ __('Rows processed') }}</th>
                            <th class="py-2 text-right">{{ __('Updated') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($this->recentImports as $import)
                            <tr class="text-neutral-700 dark:text-neutral-200">
                                <td class="py-3">
                                    <div class="font-medium">
                                        {{ $import->company?->name }}
                                    </div>
                                    <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                        {{ $import->company?->symbol }}
                                    </div>
                                </td>
                                <td class="py-3">
                                    <span class="break-all">{{ $import->original_filename }}</span>
                                </td>
                                <td class="py-3">
                                    <flux:badge variant="solid" :color="$this->statusColor($import->status)">
                                        {{ Str::headline($import->status->value) }}
                                    </flux:badge>
                                </td>
                                <td class="py-3 text-right">
                                    {{ number_format($import->processed_rows) }}
                                    @if ($import->total_rows)
                                        <span class="text-xs text-neutral-500 dark:text-neutral-400">
                                            / {{ number_format($import->total_rows) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 text-right text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ optional($import->updated_at)->diffForHumans() }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-6 text-center text-neutral-500 dark:text-neutral-400">
                                    {{ __('No imports queued yet. Start by uploading a spreadsheet.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <flux:callout icon="information-circle" variant="neutral">
        {{ __('Imports run asynchronously through Horizon. Ensure the queue worker is running to process uploaded files.') }}
    </flux:callout>
</div>
