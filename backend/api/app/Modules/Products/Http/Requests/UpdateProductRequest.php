<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Requests;

use App\Shared\Http\Requests\StrictFormRequest;
use Illuminate\Validation\Rule;

/**
 * Schema UpdateProductRequest (JSON): a partial edit, at least one field.
 * The image and the stock are not editable here; sending them is a 422
 * (StrictFormRequest rejects unknown fields).
 */
final class UpdateProductRequest extends StrictFormRequest
{
    private const array FIELDS = ['name', 'description', 'price_minor', 'category_id'];

    public function rules(): array
    {
        return [
            'name' => ['bail', 'required_without_all:description,price_minor,category_id', 'string', 'min:2', 'max:120'],
            'description' => ['bail', 'sometimes', 'nullable', 'string', 'max:2000'],
            'price_minor' => ['bail', 'sometimes', 'integer', 'min:1', 'max:100000000000'],
            'category_id' => ['bail', 'sometimes', 'string', 'ulid', Rule::exists('categories', 'id')],
        ];
    }

    /**
     * ULIDs are case-insensitive; the stored form is upper case.
     */
    protected function prepareForValidation(): void
    {
        $category = $this->input('category_id');

        if (is_string($category)) {
            $this->merge(['category_id' => strtoupper($category)]);
        }
    }

    /**
     * The validated changes, normalised like on creation (trimmed text,
     * upper-case ULID, empty description for null).
     *
     * @return array<string, string|int>
     */
    public function changes(): array
    {
        $changes = [];

        foreach (self::FIELDS as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $changes[$field] = match ($field) {
                'price_minor' => $this->integer($field),
                'category_id' => strtoupper($this->string($field)->toString()),
                default => $this->string($field)->trim()->toString(),
            };
        }

        return $changes;
    }
}
