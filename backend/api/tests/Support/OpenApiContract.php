<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Read-only view of contracts/openapi/openapi.yaml, mounted read-only at
 * /contracts in the api-test container (compose.yaml; same path as gesso.php).
 */
final class OpenApiContract
{
    public const string PATH = '/contracts/openapi/openapi.yaml';

    private const array HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * Parsed once per test process.
     *
     * @var array<mixed>|null
     */
    private static ?array $document = null;

    public static function path(): string
    {
        if (! is_file(self::PATH)) {
            throw new RuntimeException('The contract is not mounted at '.self::PATH.' (run the suite with make verify-api).');
        }

        return self::PATH;
    }

    /**
     * Base path of every operation, from the first `servers` entry (e.g. /api/v1).
     */
    public static function basePath(): string
    {
        $servers = self::document()['servers'] ?? null;
        $url = is_array($servers) && is_array($servers[0] ?? null) ? ($servers[0]['url'] ?? null) : null;

        if (! is_string($url) || ! str_starts_with($url, '/')) {
            throw new RuntimeException('The contract must declare a relative server url such as /api/v1.');
        }

        return rtrim($url, '/');
    }

    /**
     * Every documented operation as "METHOD /full/path/{}", with path
     * parameter names replaced by {} so naming differences do not matter.
     *
     * @return list<string>
     */
    public static function operations(): array
    {
        $paths = self::document()['paths'] ?? null;

        if (! is_array($paths)) {
            throw new RuntimeException('The contract has no paths.');
        }

        $basePath = self::basePath();
        $operations = [];

        foreach ($paths as $path => $item) {
            if (! is_string($path) || ! is_array($item)) {
                continue;
            }

            foreach (array_keys($item) as $method) {
                if (in_array($method, self::HTTP_METHODS, true)) {
                    $operations[] = self::operation($method, $basePath.$path);
                }
            }
        }

        sort($operations);

        return $operations;
    }

    public static function operation(string $method, string $path): string
    {
        $normalized = preg_replace('/\{[^}]+}/', '{}', '/'.ltrim($path, '/'));

        return strtoupper($method).' '.($normalized ?? $path);
    }

    /**
     * @return array<mixed>
     */
    public static function document(): array
    {
        if (self::$document === null) {
            $document = Yaml::parseFile(self::path());

            if (! is_array($document)) {
                throw new RuntimeException('The contract is not a YAML mapping.');
            }

            self::$document = $document;
        }

        return self::$document;
    }
}
