<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Modules\Users\Actions\RegisterUser;
use App\Modules\Users\Http\Requests\RegisterRequest;
use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/auth/register (registerUser).
 */
final readonly class RegisterController
{
    public function __construct(private RegisterUser $register) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = $this->register->handle(
            $request->string('name')->trim()->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        return (new UserResource($user))
            ->response($request)
            ->setStatusCode(201)
            ->header('Location', url('/api/v1/auth/me'));
    }
}
