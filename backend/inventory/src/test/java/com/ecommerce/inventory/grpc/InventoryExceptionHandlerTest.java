package com.ecommerce.inventory.grpc;

import static org.assertj.core.api.Assertions.assertThat;

import io.grpc.Status;
import io.grpc.StatusException;
import java.sql.SQLException;
import java.sql.SQLTransientConnectionException;
import org.junit.jupiter.api.Test;
import org.springframework.dao.DataAccessResourceFailureException;
import org.springframework.jdbc.UncategorizedSQLException;
import org.springframework.transaction.CannotCreateTransactionException;

class InventoryExceptionHandlerTest {

  private final InventoryExceptionHandler handler = new InventoryExceptionHandler();

  @Test
  void aLockTimeoutOrDeadlockIsAbortedSoTheCallerMayRetry() {
    assertThat(codeFor(sqlFailure("55P03"))).isEqualTo(Status.Code.ABORTED);
    assertThat(codeFor(sqlFailure("40P01"))).isEqualTo(Status.Code.ABORTED);
  }

  @Test
  void aStatementTimeoutWithoutAnExpiredDeadlineIsAborted() {
    assertThat(codeFor(sqlFailure("57014"))).isEqualTo(Status.Code.ABORTED);
  }

  @Test
  void noDatabaseConnectionIsUnavailable() {
    StatusException status =
        handler.handleException(
            new CannotCreateTransactionException(
                "Could not open JDBC Connection",
                new SQLTransientConnectionException("pool exhausted")));

    assertThat(status.getStatus().getCode()).isEqualTo(Status.Code.UNAVAILABLE);
  }

  @Test
  void anythingElseIsInternalWithoutDetails() {
    StatusException status =
        handler.handleException(
            new DataAccessResourceFailureException("db host 10.0.0.5 password=secret"));

    assertThat(status.getStatus().getCode()).isEqualTo(Status.Code.INTERNAL);
    assertThat(status.getStatus().getDescription()).isEqualTo("internal error");
  }

  private Status.Code codeFor(Throwable exception) {
    return handler.handleException(exception).getStatus().getCode();
  }

  private static RuntimeException sqlFailure(String sqlState) {
    return new UncategorizedSQLException("statement", "SQL", new SQLException("db", sqlState));
  }
}
