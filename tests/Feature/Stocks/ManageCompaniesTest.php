<?php

declare(strict_types=1);

use App\Domain\Stocks\Models\Company;
use App\Models\User;
use Livewire\Volt\Volt;

it('creates a company via the dashboard manager', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('dashboard.companies')
        ->set('name', 'Acme Corporation')
        ->set('symbol', 'acme')
        ->call('createCompany')
        ->assertDispatched('company-created')
        ->assertSet('name', '')
        ->assertSet('symbol', '');

    /** @var Company|null $company */
    $company = Company::query()->where('symbol', 'ACME')->first();

    expect($company)->not->toBeNull()
        ->and($company->slug)->toBe('acme-corporation');
});

it('validates ticker symbols for uniqueness and formatting', function (): void {
    $this->actingAs(User::factory()->create());

    Company::factory()->create([
        'name' => 'Existing Inc',
        'symbol' => 'EXST',
        'slug' => 'existing-inc',
    ]);

    Volt::test('dashboard.companies')
        ->set('name', 'Another Company')
        ->set('symbol', 'exst')
        ->call('createCompany')
        ->assertHasErrors(['symbol' => ['unique']]);

    Volt::test('dashboard.companies')
        ->set('name', 'Bad Symbol Co')
        ->set('symbol', 'bad symbol!')
        ->call('createCompany')
        ->assertHasErrors(['symbol' => ['regex']]);
});
