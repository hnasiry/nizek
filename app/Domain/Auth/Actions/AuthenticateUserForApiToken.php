<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthenticateUserForApiToken
{
    /**
     * @throws ValidationException
     */
    public function handle(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('The provided credentials are incorrect.')],
            ]);
        }

        return $user;
    }
}
