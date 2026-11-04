<?php

declare(strict_types=1);

namespace App\Shared\Http\Problems;

use RuntimeException;

/**
 * Default DomainProblem: a catalog code with an optional Arabic detail and
 * extensions. Modules extend it for their own named exceptions.
 */
class ProblemException extends RuntimeException implements DomainProblem
{
    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private readonly ProblemCode $problemCode,
        private readonly ?string $detail = null,
        private readonly array $extensions = [],
        private readonly array $headers = [],
    ) {
        parent::__construct($problemCode->value);
    }

    final public function problemCode(): ProblemCode
    {
        return $this->problemCode;
    }

    final public function problemDetail(): ?string
    {
        return $this->detail;
    }

    final public function problemExtensions(): array
    {
        return $this->extensions;
    }

    final public function problemHeaders(): array
    {
        return $this->headers;
    }
}
