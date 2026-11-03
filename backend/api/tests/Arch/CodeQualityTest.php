<?php

declare(strict_types=1);

arch('application code declares strict types', function (): void {
    expect(['App', 'Database\\Seeders'])->toUseStrictTypes();
});

// Same lists as Pest's `php` and `security` presets, written out so the
// rules are visible here and statically analysable.
arch('no debugging helpers', function (): void {
    expect([
        'dd', 'dump', 'ray', 'ds', 'trap', 'var_dump', 'var_export', 'print_r',
        'debug_zval_dump', 'debug_backtrace', 'debug_print_backtrace', 'phpinfo',
        'xdebug_break', 'xdebug_info', 'xdebug_var_dump',
    ])->not()->toBeUsed();
});

arch('no insecure functions', function (): void {
    expect([
        'md5', 'sha1', 'uniqid', 'rand', 'mt_rand', 'tempnam', 'str_shuffle',
        'shuffle', 'array_rand', 'eval', 'exec', 'shell_exec', 'system',
        'passthru', 'create_function', 'unserialize', 'extract', 'mb_parse_str',
        'dl', 'assert',
    ])->not()->toBeUsed();
});

arch('generated gRPC stubs are only used by the inventory client', function (): void {
    expect('Ecommerce\\Inventory\\V1')->toOnlyBeUsedIn('App\\Shared\\Inventory');
});
