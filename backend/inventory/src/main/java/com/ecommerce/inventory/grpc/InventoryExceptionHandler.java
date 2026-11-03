package com.ecommerce.inventory.grpc;

import com.ecommerce.inventory.domain.StockWouldBecomeNegativeException;
import com.ecommerce.inventory.persistence.SqlStates;
import io.grpc.Status;
import io.grpc.StatusException;
import io.grpc.StatusRuntimeException;
import java.sql.SQLTransientConnectionException;
import java.util.Optional;
import java.util.Set;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.grpc.server.exception.GrpcExceptionHandler;
import org.springframework.jdbc.CannotGetJdbcConnectionException;
import org.springframework.stereotype.Component;
import org.springframework.transaction.CannotCreateTransactionException;

/**
 * Maps every exception leaving an RPC to a gRPC status (docs/03 section 3). Messages never carry
 * internal details; those only go to the log, which carries the request id.
 *
 * <ul>
 *   <li>invalid input: INVALID_ARGUMENT naming the field
 *   <li>AdjustStock below zero: FAILED_PRECONDITION
 *   <li>lock_timeout, deadlock, serialization failure: ABORTED (the caller treats it as unavailable
 *       and compensates)
 *   <li>statement_timeout: DEADLINE_EXCEEDED once the caller's deadline has passed, else ABORTED
 *   <li>no database connection: UNAVAILABLE
 *   <li>anything else: INTERNAL
 * </ul>
 */
@Component
public class InventoryExceptionHandler implements GrpcExceptionHandler {

  private static final Logger log = LoggerFactory.getLogger(InventoryExceptionHandler.class);

  private static final Set<String> RETRYABLE_STATES =
      Set.of(
          SqlStates.LOCK_NOT_AVAILABLE,
          SqlStates.DEADLOCK_DETECTED,
          SqlStates.SERIALIZATION_FAILURE);

  @Override
  public StatusException handleException(Throwable exception) {
    return switch (exception) {
      case StatusException e -> e;
      case StatusRuntimeException e -> e.getStatus().asException(e.getTrailers());
      case InvalidRequestException e ->
          Status.INVALID_ARGUMENT.withDescription(e.getMessage()).asException();
      case StockWouldBecomeNegativeException e ->
          Status.FAILED_PRECONDITION
              .withDescription("the adjustment would make the available quantity negative")
              .asException();
      default -> infrastructureFailure(exception);
    };
  }

  private static StatusException infrastructureFailure(Throwable exception) {
    Optional<String> sqlState = SqlStates.of(exception);
    if (sqlState.filter(RETRYABLE_STATES::contains).isPresent()) {
      log.warn("Inventory call aborted by the database: {}", exception.getMessage());
      return Status.ABORTED.withDescription("inventory busy, retry later").asException();
    }
    if (sqlState.filter(SqlStates.QUERY_CANCELED::equals).isPresent()) {
      log.warn("Inventory statement timed out: {}", exception.getMessage());
      return CallBudget.deadlineExpired()
          ? Status.DEADLINE_EXCEEDED.withDescription("deadline exceeded").asException()
          : Status.ABORTED.withDescription("inventory busy, retry later").asException();
    }
    if (isConnectionFailure(exception, sqlState)) {
      log.error("Inventory database unavailable", exception);
      return Status.UNAVAILABLE.withDescription("inventory unavailable").asException();
    }
    log.error("Unexpected failure in a gRPC call", exception);
    return Status.INTERNAL.withDescription("internal error").asException();
  }

  private static boolean isConnectionFailure(Throwable exception, Optional<String> sqlState) {
    if (sqlState
        .filter(state -> state.startsWith(SqlStates.CONNECTION_EXCEPTION_CLASS))
        .isPresent()) {
      return true;
    }
    for (Throwable t = exception; t != null; t = t.getCause()) {
      if (t instanceof CannotCreateTransactionException
          || t instanceof CannotGetJdbcConnectionException
          || t instanceof SQLTransientConnectionException) {
        return true;
      }
    }
    return false;
  }
}
