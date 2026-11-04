<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Domain\OrderStatus;
use App\Shared\Auth\AuthUser;
use App\Shared\Http\Pagination\PageRequest;
use App\Shared\Http\Requests\StrictFormRequest;
use Illuminate\Validation\Rule;

/**
 * Query of GET /orders. `user_id` is an admin filter: a customer sending it
 * gets a 422 (prohibited), never a silently ignored parameter.
 */
final class ListOrdersRequest extends StrictFormRequest
{
    public function rules(): array
    {
        $user = $this->user();
        $isAdmin = $user instanceof AuthUser && $user->isAdmin();

        return [
            'status' => ['bail', 'sometimes', Rule::enum(OrderStatus::class)],
            'user_id' => $isAdmin ? ['bail', 'sometimes', 'string', 'ulid'] : ['prohibited'],
            ...PageRequest::rules(),
        ];
    }

    public function status(): ?OrderStatus
    {
        return $this->filled('status') ? OrderStatus::from($this->string('status')->toString()) : null;
    }

    public function userFilter(): ?string
    {
        return $this->filled('user_id') ? strtoupper($this->string('user_id')->toString()) : null;
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', PageRequest::DEFAULT_PER_PAGE);
    }
}
