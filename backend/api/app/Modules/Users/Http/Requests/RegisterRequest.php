<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use App\Shared\Http\Requests\StrictFormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Schema RegisterRequest. `role` and any other unknown field ⇒ 422.
 */
final class RegisterRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'min:2', 'max:100'],
            'email' => ['bail', 'required', 'string', 'max:254', 'email:rfc', Rule::unique('users', 'email')],
            'password' => ['bail', 'required', 'string', 'min:12', 'max:128', 'confirmed'],
            'password_confirmation' => ['bail', 'required', 'string', 'min:12', 'max:128'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
