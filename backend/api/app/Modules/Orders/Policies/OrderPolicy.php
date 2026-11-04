<?php

declare(strict_types=1);

namespace App\Modules\Orders\Policies;

use App\Modules\Orders\Models\Order;
use App\Shared\Auth\AuthUser;
use Illuminate\Auth\Access\Response;

/**
 * docs/04 section 5 (R1-SEC4, R1-SEC5). Every rule is explicit: there is no
 * Gate::before shortcut for admins.
 */
final class OrderPolicy
{
    /**
     * Customers buy; the admin does not (docs/02 section 3).
     */
    public function create(AuthUser $user): Response
    {
        return $user->isAdmin() ? Response::deny() : Response::allow();
    }

    /**
     * Another customer's order is answered as if it did not exist (D-02, BOLA).
     */
    public function view(AuthUser $user, Order $order): Response
    {
        return $user->isAdmin() || $order->user_id === $user->userId()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Only the admin changes a status (R1-SEC4): a customer gets 403, even on
     * an order of their own.
     */
    public function changeStatus(AuthUser $user): Response
    {
        return $user->isAdmin() ? Response::allow() : Response::deny();
    }
}
