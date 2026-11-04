<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;
use App\Shared\Http\Requests\StrictFormRequest;
use Illuminate\Support\Str;

/**
 * Schema CreateOrderRequest: 1..20 lines, each product at most once,
 * quantity 1..10. The Idempotency-Key header, when present, must be a UUID
 * (400 MALFORMED_REQUEST otherwise).
 */
final class CreateOrderRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['bail', 'required', 'array', 'list', 'min:1', 'max:20'],
            'items.*' => ['bail', 'required', 'array:product_id,quantity'],
            'items.*.product_id' => ['bail', 'required', 'string', 'ulid', 'distinct:ignore_case'],
            'items.*.quantity' => ['bail', 'required', 'integer', 'min:1', 'max:10'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        if ($key === null || $key === '') {
            return null;
        }

        if (! is_string($key) || ! Str::isUuid($key)) {
            throw new ProblemException(ProblemCode::MalformedRequest, 'ترويسة Idempotency-Key يجب أن تكون UUID.');
        }

        return strtolower($key);
    }

    /**
     * @return list<array{product_id: string, quantity: int}>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ((array) $this->validated('items') as $item) {
            if (is_array($item) && is_string($item['product_id'] ?? null) && is_int($item['quantity'] ?? null)) {
                $lines[] = ['product_id' => strtoupper($item['product_id']), 'quantity' => $item['quantity']];
            }
        }

        return $lines;
    }

    protected function prepareForValidation(): void
    {
        // A malformed key is refused before the body is even looked at.
        $this->idempotencyKey();
    }
}
