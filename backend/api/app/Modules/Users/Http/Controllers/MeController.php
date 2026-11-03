<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\Request;

/**
 * GET /api/v1/auth/me (getCurrentUser).
 */
final readonly class MeController
{
    public function __invoke(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
