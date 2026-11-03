package com.ecommerce.inventory.persistence;

import java.sql.SQLException;
import java.util.Optional;

/** PostgreSQL SQLSTATE codes this service reacts to, read from an exception's cause chain. */
public final class SqlStates {

  /** {@code check_violation}: a CHECK constraint, e.g. {@code available >= 0}, rejected a row. */
  public static final String CHECK_VIOLATION = "23514";

  private SqlStates() {}

  /** The SQLSTATE of the first {@link SQLException} in the cause chain, if any. */
  public static Optional<String> of(Throwable exception) {
    for (Throwable t = exception; t != null; t = t.getCause()) {
      if (t instanceof SQLException sql && sql.getSQLState() != null) {
        return Optional.of(sql.getSQLState());
      }
    }
    return Optional.empty();
  }
}
