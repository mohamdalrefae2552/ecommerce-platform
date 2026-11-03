<?php

declare(strict_types=1);

namespace App\Shared\Http\Problems;

use Throwable;

/**
 * A domain exception that knows its problem+json representation. Modules
 * throw these instead of abort() with free text; ProblemRenderer turns them
 * into responses.
 */
interface DomainProblem extends Throwable
{
    public function problemCode(): ProblemCode;

    /**
     * Arabic message for the end user; null uses the code's default.
     */
    public function problemDetail(): ?string;

    /**
     * Code-specific members (e.g. `shortages`, `retry_after`).
     *
     * @return array<string, mixed>
     */
    public function problemExtensions(): array;

    /**
     * @return array<string, string>
     */
    public function problemHeaders(): array;
}
