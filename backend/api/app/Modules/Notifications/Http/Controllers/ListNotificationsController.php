<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Http\Requests\ListNotificationsRequest;
use App\Modules\Notifications\Models\UserNotification;
use App\Shared\Auth\CurrentUser;
use App\Shared\Http\Pagination\PageEnvelope;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/notifications (listNotifications): only the caller's own,
 * newest first (restricted in SQL).
 */
final readonly class ListNotificationsController
{
    public function __invoke(ListNotificationsRequest $request): JsonResponse
    {
        $page = UserNotification::query()
            ->where('user_id', CurrentUser::of($request)->userId())
            ->when($request->onlyUnread(), fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        /** @var list<UserNotification> $notifications */
        $notifications = array_values($page->items());

        return PageEnvelope::response($request, $page, array_map(static fn (UserNotification $n): array => $n->toApi(), $notifications));
    }
}
