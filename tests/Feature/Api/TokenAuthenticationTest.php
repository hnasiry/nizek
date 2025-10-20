<?php

declare(strict_types=1);

use App\Domain\Stocks\Models\Company;
use App\Models\User;
use Illuminate\Testing\TestResponse;

test('users can exchange credentials for an API token', function (): void {
    $user = User::factory()->create([
        'email' => 'dev@example.com',
        'password' => bcrypt('top-secret'),
    ]);

    /** @var TestResponse $response */
    $response = $this->postJson(route('api.auth.login'), [
        'email' => 'dev@example.com',
        'password' => 'top-secret',
        'token_name' => 'Postman',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['token']);

    expect($user->tokens()->where('name', 'Postman')->count())->toBe(1);
});

test('invalid credentials are rejected', function (): void {
    User::factory()->create([
        'email' => 'dev@example.com',
        'password' => bcrypt('valid-password'),
    ]);

    $response = $this->postJson(route('api.auth.login'), [
        'email' => 'dev@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('token endpoint validates input payload', function (array $payload, array $expectedErrors): void {
    $response = $this->postJson(route('api.auth.login'), $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors($expectedErrors);
})->with([
    'missing email' => [
        ['password' => 'password'],
        ['email'],
    ],
    'missing password' => [
        ['email' => 'dev@example.com'],
        ['password'],
    ],
]);

test('bearer tokens can authenticate reporting endpoints', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $token = $user->createToken('CLI')->plainTextToken;

    $response = $this->withToken($token)->getJson(
        route('api.companies.stock-prices.performance', $company),
    );

    $response->assertOk();
});
