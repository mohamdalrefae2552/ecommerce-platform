<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use App\Shared\Http\Pagination\PageRequest;
use App\Shared\Http\Requests\StrictFormRequest;

final class ListNotificationsRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'unread' => ['bail', 'sometimes', 'in:true,false'],
            ...PageRequest::rules(),
        ];
    }

    public function onlyUnread(): bool
    {
        return $this->input('unread') === 'true';
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
