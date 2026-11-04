package com.ecommerce.inventory.persistence;

import java.time.Duration;
import org.springframework.jdbc.core.simple.JdbcClient;
import org.springframework.stereotype.Component;

/**
 * Bounds the current transaction (SET LOCAL semantics): lock_timeout and statement_timeout are the
 * configured values, shortened to the caller's remaining deadline so that no statement keeps
 * working, or waiting for a lock, after the caller has given up (docs/03 section 2).
 */
@Component
public class TransactionTimeouts {

  private final JdbcClient jdbc;
  private final DatabaseTimeouts limits;

  public TransactionTimeouts(JdbcClient jdbc, DatabaseTimeouts limits) {
    this.jdbc = jdbc;
    this.limits = limits;
  }

  /** Must be the first statement of a transaction. */
  public void limitTo(Duration budget) {
    jdbc.sql(
            """
            SELECT set_config('lock_timeout', :lockTimeout, true),
                   set_config('statement_timeout', :statementTimeout, true)
            """)
        .param("lockTimeout", millis(shorter(limits.lockTimeout(), budget)))
        .param("statementTimeout", millis(shorter(limits.statementTimeout(), budget)))
        .query()
        .listOfRows();
  }

  private static Duration shorter(Duration a, Duration b) {
    return a.compareTo(b) <= 0 ? a : b;
  }

  /** Whole milliseconds, at least 1: zero would disable the PostgreSQL timeout. */
  private static String millis(Duration duration) {
    return Math.max(1, duration.toMillis()) + "ms";
  }
}
