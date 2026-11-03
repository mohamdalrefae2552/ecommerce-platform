<?php

declare(strict_types=1);

// Contract testing (studio-design/openapi-contract-testing, ADR-0010), read by
// the PHPUnit extension in phpunit.xml and by the Laravel adapter.
// contracts/ is mounted read-only at /contracts in the api-test container
// (compose.yaml), so the suite always validates against the real contract.
return [
    'spec' => [
        'base_path' => '/contracts/openapi',
        'default' => 'openapi',
        'names' => ['openapi'],
        // Contract paths are relative to its server url /api/v1.
        'strip_prefixes' => ['/api/v1'],
    ],
    'validation' => [
        // Validate every status code, 5xx included: the contract documents
        // its problem+json errors.
        'skip_response_codes' => [],
    ],
];
