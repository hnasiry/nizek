<?php

use App\Domain\Auth\Actions\IssueUserApiToken;
use App\Domain\Auth\Actions\RevokeUserApiToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    public string $newTokenName = '';

    public ?string $plainTextToken = null;

    #[Computed(cache: false)]
    public function tokens(): Collection
    {
        $user = Auth::user();

        if ($user === null) {
            return collect();
        }

        return $user->tokens()
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'last_used_at', 'created_at']);
    }

    public function createToken(): void
    {
        $validated = $this->validate(
            [
                'newTokenName' => ['required', 'string', 'max:255'],
            ],
            [],
            [
                'newTokenName' => __('token name'),
            ],
        );

        $token = app(IssueUserApiToken::class)->handle(
            Auth::user(),
            $validated['newTokenName'],
        );

        $this->plainTextToken = $token->plainTextToken;

        $this->reset('newTokenName');

        $this->dispatch('token-created');
    }

    public function regenerateToken(int $tokenId): void
    {
        $user = Auth::user();

        $existingToken = $user->tokens()->whereKey($tokenId)->first();

        if ($existingToken === null) {
            return;
        }

        app(RevokeUserApiToken::class)->handle($user, $tokenId);

        $newToken = app(IssueUserApiToken::class)->handle($user, $existingToken->name);

        $this->plainTextToken = $newToken->plainTextToken;

        $this->dispatch('token-regenerated');
    }

    public function revokeToken(int $tokenId): void
    {
        app(RevokeUserApiToken::class)->handle(Auth::user(), $tokenId);

        $this->plainTextToken = null;

        $this->dispatch('token-revoked');
    }

    public function resetPlainTextToken(): void
    {
        $this->plainTextToken = null;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('API tokens')" :subheading="__('Generate tokens for CLI or third-party integrations')">
        <div class="mt-6 space-y-6">
            @if ($plainTextToken)
                <flux:callout icon="key" variant="success" class="flex flex-col gap-4">
                    <div>
                        <flux:heading size="md">{{ __('Your new API token') }}</flux:heading>
                        <flux:text class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('Copy this token now. For security reasons it will not be shown again.') }}
                        </flux:text>
                    </div>

                    <div class="flex items-center gap-3">
                        <flux:input readonly value="{{ $plainTextToken }}" class="font-mono text-sm" />
                        <flux:button
                            icon="clipboard"
                            variant="ghost"
                            x-data="{ token: @js($plainTextToken), copied: false }"
                            x-on:click.prevent="navigator.clipboard.writeText(token).then(() => { copied = true; setTimeout(() => copied = false, 2000); })"
                        >
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied">{{ __('Copied!') }}</span>
                        </flux:button>
                        <flux:button variant="ghost" wire:click="resetPlainTextToken">
                            {{ __('Dismiss') }}
                        </flux:button>
                    </div>
                </flux:callout>
            @endif

            <form wire:submit="createToken" class="space-y-4">
                <flux:input
                    wire:model="newTokenName"
                    :label="__('Token name')"
                    :placeholder="__('e.g. Local development or Postman')"
                    required
                />

                <flux:button type="submit" variant="primary">
                    {{ __('Generate token') }}
                </flux:button>
            </form>

            <div>
                <flux:heading size="sm" class="mb-4 uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                    {{ __('Existing tokens') }}
                </flux:heading>

                @if ($this->tokens->isEmpty())
                    <flux:callout icon="shield-check" variant="muted">
                        {{ __('You have not created any API tokens yet.') }}
                    </flux:callout>
                @else
                    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                            <thead class="bg-neutral-50 dark:bg-neutral-800">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">
                                        {{ __('Name') }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">
                                        {{ __('Created') }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">
                                        {{ __('Last used') }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">
                                        {{ __('Actions') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                @foreach ($this->tokens as $token)
                                    <tr wire:key="token-{{ $token->id }}">
                                        <td class="px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                            {{ $token->name }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-300">
                                            {{ optional($token->created_at)->format('Y-m-d H:i') ?? __('N/A') }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-300">
                                            {{ optional($token->last_used_at)?->diffForHumans() ?? __('Never') }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center justify-end gap-2">
                                                <flux:button variant="ghost" size="sm" wire:click="regenerateToken({{ $token->id }})">
                                                    {{ __('Regenerate') }}
                                                </flux:button>
                                                <flux:button
                                                    variant="ghost"
                                                    color="red"
                                                    size="sm"
                                                    wire:click="revokeToken({{ $token->id }})"
                                                    wire:confirm="{{ __('Are you sure you want to revoke this token?') }}"
                                                >
                                                    {{ __('Revoke') }}
                                                </flux:button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </x-settings.layout>
</section>
