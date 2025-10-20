<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\AuthenticateUserForApiToken;
use App\Domain\Auth\Actions\IssueUserApiToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\IssueTokenRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class IssueTokenController extends Controller
{
    public function __construct(
        private readonly AuthenticateUserForApiToken $authenticateUser,
        private readonly IssueUserApiToken $issuer,
    ) {}

    public function __invoke(IssueTokenRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->authenticateUser->handle(
            $validated['email'],
            $validated['password'],
        );

        $newToken = $this->issuer->handle(
            $user,
            $request->tokenName(),
        );

        return response()->json([
            'token' => $newToken->plainTextToken,
        ], Response::HTTP_CREATED);
    }
}
