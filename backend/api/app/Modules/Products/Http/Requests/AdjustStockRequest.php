<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Requests;

use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;
use App\Shared\Http\Requests\StrictFormRequest;
use Illuminate\Support\Str;

/**
 * Schema AdjustStockRequest. The Idempotency-Key header is required and
 * must be a UUID (400 MALFORMED_REQUEST otherwise).
 */
final class AdjustStockRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'delta' => ['bail', 'required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'note' => ['bail', 'sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function idempotencyKey(): string
    {
        $key = $this->header('Idempotency-Key');

        if (! is_string($key) || ! Str::isUuid($key)) {
            throw new ProblemException(ProblemCode::MalformedRequest, 'ترويسة Idempotency-Key مطلوبة ويجب أن تكون UUID.');
        }

        return strtolower($key);
    }

    public function delta(): int
    {
        return $this->integer('delta');
    }

    public function note(): string
    {
        return $this->string('note')->trim()->toString();
    }

    protected function prepareForValidation(): void
    {
        $this->idempotencyKey();
    }
}
