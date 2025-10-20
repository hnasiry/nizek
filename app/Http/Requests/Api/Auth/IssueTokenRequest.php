<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class IssueTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'token_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function tokenName(): string
    {
        return (string) ($this->input('token_name') ?: __('Personal Access Token'));
    }
}
