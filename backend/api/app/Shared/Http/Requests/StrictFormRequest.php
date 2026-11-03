<?php

declare(strict_types=1);

namespace App\Shared\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * FormRequest that rejects unknown body fields with 422 (docs/04 section 6):
 * sending `role`, `user_id` or `status` where they are not accepted can
 * never be silently ignored, let alone applied. Authorization is done by
 * policies, not here.
 */
abstract class StrictFormRequest extends FormRequest
{
    final public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $allowed = array_map(static fn (string $rule): string => explode('.', $rule, 2)[0], array_keys($this->rules()));

                foreach (array_keys($this->request->all() + $this->files->all()) as $field) {
                    if (! in_array((string) $field, $allowed, true)) {
                        $validator->errors()->add((string) $field, 'هذا الحقل غير مسموح.');
                    }
                }
            },
        ];
    }
}
