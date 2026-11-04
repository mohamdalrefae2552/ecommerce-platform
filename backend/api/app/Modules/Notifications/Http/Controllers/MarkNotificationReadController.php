<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Models\UserNotification;
use App\Shared\Auth\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * PATCH /api/v1/notifications/{notificationId}/read (markNotificationRead):
 * idempotent; another user's notification is 404 (looked up by owner in SQL).
 */
final readonly class MarkNotificationReadController
{
    public function __invoke(Request $request, string $notificationId): JsonResponse
    {
        $notification = UserNotification::query()
            ->where('user_id', CurrentUser::of($request)->userId())
            ->findOrFail(strtoupper($notificationId));

        if ($notification->read_at === null) {
            $notification->read_at = Carbon::now();
            $notification->save();
        }

        return new JsonResponse(['data' => $notification->toApi()]);
    }
}
