package com.ecommerce.inventory.persistence;

import java.time.Duration;
import org.springframework.boot.context.properties.ConfigurationProperties;

/**
 * Upper bounds for every transaction (from INVENTORY_LOCK_TIMEOUT_MS and
 * INVENTORY_STATEMENT_TIMEOUT_MS), below the longest client deadline (docs/03 section 2).
 *
 * @param lockTimeout longest wait for a row lock before the call is ABORTED
 * @param statementTimeout longest run of a single statement
 */
@ConfigurationProperties("inventory.db")
public record DatabaseTimeouts(Duration lockTimeout, Duration statementTimeout) {

  public DatabaseTimeouts {
    if (lockTimeout == null || statementTimeout == null) {
      throw new IllegalArgumentException("inventory.db lock and statement timeouts are required");
    }
    if (lockTimeout.isNegative()
        || lockTimeout.isZero()
        || statementTimeout.isNegative()
        || statementTimeout.isZero()) {
      throw new IllegalArgumentException("inventory.db timeouts must be positive");
    }
  }
}
