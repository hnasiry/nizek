<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\NewAccessToken;

final class IssueUserApiToken
{
    /**
     * @param  list<string>  $abilities
     */
    public function handle(User $user, string $name, array $abilities = ['*'], ?CarbonImmutable $expiresAt = null): NewAccessToken
    {
        return $user->createToken($name, $abilities, $expiresAt);
    }
}
