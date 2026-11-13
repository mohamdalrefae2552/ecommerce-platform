<?php

declare(strict_types=1);

namespace App\Shared\Realtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/broadcasting/auth (requirement 4): the browser asks for
 * permission to subscribe to a private WebSocket channel. The route is
 * behind the JWT guard (identity); the channel rule registered by the owning
 * module decides (authorisation). Only then is the subscription signed with
 * the Reverb secret, which the browser never sees; Reverb refuses a
 * subscription without that signature.
 */
final class AuthorizeChannelController
{
    public function __invoke(Request $request): JsonResponse
    {
        $socketId = $request->input('socket_id');
        $channel = $request->input('channel_name');

        // Pusher protocol: "<digits>.<digits>" and a private channel name.
        // Checked strictly: both values are signed below.
        $errors = [];

        if (! is_string($socketId) || preg_match('/^\d+\.\d+$/D', $socketId) !== 1) {
            $errors['socket_id'] = 'معرّف الاتصال غير صالح.';
        }

        if (! is_string($channel) || preg_match('/^private-[A-Za-z0-9_.\-]{1,150}$/D', $channel) !== 1) {
            $errors['channel_name'] = 'اسم القناة غير صالح.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Throws AccessDeniedHttpException (403) for an unknown channel or a
        // rule that says no.
        $signature = Broadcast::auth($request);

        return new JsonResponse(['auth' => is_array($signature) ? ($signature['auth'] ?? '') : '']);
    }
}
