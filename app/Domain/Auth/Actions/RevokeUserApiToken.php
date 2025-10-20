<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Models\User;

final class RevokeUserApiToken
{
    public function handle(User $user, int $tokenId): void
    {
        $user->tokens()->whereKey($tokenId)->delete();
    }
}
