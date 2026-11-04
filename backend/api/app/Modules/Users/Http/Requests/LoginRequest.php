<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use App\Shared\Http\Requests\StrictFormRequest;

/**
 * Schema LoginRequest.
 */
final class LoginRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'string', 'max:254', 'email:rfc'],
            'password' => ['bail', 'required', 'string', 'max:128'],
        ];
    }

    public function email(): string
    {
        return mb_strtolower(trim($this->string('email')->toString()));
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }
}
