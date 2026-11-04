<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Domain\OrderStatus;
use App\Shared\Http\Requests\StrictFormRequest;
use Illuminate\Validation\Rule;

/**
 * Schema ChangeOrderStatusRequest.
 */
final class ChangeOrderStatusRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['bail', 'required', 'string', Rule::enum(OrderStatus::class)],
            'note' => ['bail', 'sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function status(): OrderStatus
    {
        return OrderStatus::from($this->string('status')->toString());
    }

    public function note(): ?string
    {
        return $this->filled('note') ? $this->string('note')->trim()->toString() : null;
    }
}
