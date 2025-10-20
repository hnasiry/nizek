<?php

declare(strict_types=1);

use App\Domain\Auth\Actions\IssueUserApiToken;
use App\Models\User;
use Livewire\Volt\Volt;

test('users can create personal access tokens from settings page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('settings.api-tokens')
        ->set('newTokenName', 'Local CLI')
        ->call('createToken');

    $component->assertHasNoErrors();

    $plainText = $component->get('plainTextToken');

    expect($plainText)->toBeString()
        ->and(str_contains($plainText, '|'))->toBeTrue();

    expect($user->tokens()->where('name', 'Local CLI')->count())->toBe(1);
});

test('plain text token can be dismissed after viewing', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('settings.api-tokens');

    $component->set('newTokenName', 'Temp token')
        ->call('createToken');

    $component->call('resetPlainTextToken');

    expect($component->get('plainTextToken'))->toBeNull();
});

test('tokens can be regenerated to rotate credentials', function (): void {
    $user = User::factory()->create();

    $token = app(IssueUserApiToken::class)->handle($user, 'Integration');

    $originalId = $user->tokens()->sole()->getKey();
    $originalHash = $user->tokens()->sole()->token;

    $this->actingAs($user);

    $component = Volt::test('settings.api-tokens');

    $component->call('regenerateToken', $originalId);

    $component->assertHasNoErrors();

    $user->refresh();

    $tokens = $user->tokens;

    expect($tokens)->toHaveCount(1);
    expect($tokens->first()->getKey())->not->toBe($originalId);
    expect($tokens->first()->token)->not->toBe($originalHash);
    expect($component->get('plainTextToken'))->toBeString();
});

test('tokens can be revoked from settings page', function (): void {
    $user = User::factory()->create();

    $token = $user->createToken('Integration');
    $tokenId = (int) $token->accessToken->getKey();

    $this->actingAs($user);

    $component = Volt::test('settings.api-tokens');

    $component->call('revokeToken', $tokenId);

    $component->assertHasNoErrors();

    expect($user->tokens()->count())->toBe(0);
});
