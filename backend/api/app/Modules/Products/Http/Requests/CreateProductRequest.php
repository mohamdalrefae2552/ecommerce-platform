<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Requests;

use App\Shared\Http\Requests\StrictFormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Schema CreateProductRequest (multipart). The image type is checked by
 * content, not by extension; at most 2 MB and 4000x4000 px.
 */
final class CreateProductRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'min:2', 'max:120'],
            'description' => ['bail', 'sometimes', 'nullable', 'string', 'max:2000'],
            'price_minor' => ['bail', 'required', 'integer', 'min:1', 'max:100000000000'],
            'category_id' => ['bail', 'required', 'string', 'ulid', Rule::exists('categories', 'id')],
            'initial_stock' => ['bail', 'sometimes', 'integer', 'min:0', 'max:1000000'],
            'image' => ['bail', 'required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ];
    }

    public function imageFile(): UploadedFile
    {
        $image = $this->file('image');

        if (! $image instanceof UploadedFile) {
            throw ValidationException::withMessages(['image' => 'الصورة مطلوبة.']);
        }

        return $image;
    }
}
