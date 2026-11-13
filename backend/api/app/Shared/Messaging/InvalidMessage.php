<?php

declare(strict_types=1);

namespace App\Shared\Messaging;

use RuntimeException;

/**
 * The message can never be handled (unreadable or missing data): retrying is
 * pointless, the consumer parks it in the dead-letter queue at once.
 */
final class InvalidMessage extends RuntimeException {}
