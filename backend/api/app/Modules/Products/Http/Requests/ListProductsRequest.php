<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Requests;

use App\Shared\Http\Pagination\PageRequest;
use App\Shared\Http\Requests\StrictFormRequest;
use Illuminate\Validation\Rule;

/**
 * Query of GET /products: an unknown category slug is a 422 on `category`.
 */
final class ListProductsRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'category' => ['bail', 'sometimes', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::exists('categories', 'slug')],
            'q' => ['bail', 'sometimes', 'string', 'min:1', 'max:100'],
            ...PageRequest::rules(),
        ];
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
