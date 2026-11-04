<?php

declare(strict_types=1);

namespace App\Shared\Inventory\Exceptions;

use RuntimeException;

/**
 * The inventory answered something the contract does not allow
 * (INVALID_ARGUMENT, INTERNAL, an id that was not sent, a negative
 * quantity...). Reported as an error and answered as 500 (OWASP API10).
 */
final class InventoryContractViolation extends RuntimeException {}
