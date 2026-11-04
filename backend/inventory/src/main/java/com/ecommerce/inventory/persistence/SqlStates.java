package com.ecommerce.inventory.persistence;

import java.sql.SQLException;
import java.util.Optional;

/** PostgreSQL SQLSTATE codes this service reacts to, read from an exception's cause chain. */
public final class SqlStates {

  /** {@code check_violation}: a CHECK constraint, e.g. {@code available >= 0}, rejected a row. */
  public static final String CHECK_VIOLATION = "23514";

  /** {@code lock_not_available}: lock_timeout expired while waiting for a row lock. */
  public static final String LOCK_NOT_AVAILABLE = "55P03";

  /** {@code query_canceled}: statement_timeout expired. */
  public static final String QUERY_CANCELED = "57014";

  /** {@code deadlock_detected}. */
  public static final String DEADLOCK_DETECTED = "40P01";

  /** {@code serialization_failure}. */
  public static final String SERIALIZATION_FAILURE = "40001";

  /** Class 08, connection exceptions (database unreachable, connection lost). */
  public static final String CONNECTION_EXCEPTION_CLASS = "08";

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
