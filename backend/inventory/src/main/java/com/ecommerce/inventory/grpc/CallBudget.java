package com.ecommerce.inventory.grpc;

import io.grpc.Context;
import io.grpc.Deadline;
import io.grpc.Status;
import java.time.Duration;
import java.util.concurrent.TimeUnit;

/**
 * The time the caller still allows, read from the gRPC deadline (proto rule 5: no work after the
 * deadline). Checked before any transaction starts; the result then bounds the transaction.
 */
final class CallBudget {

  /** Budget of a call without a deadline: the configured database timeouts are then the limit. */
  static final Duration NO_DEADLINE = Duration.ofDays(1);

  private CallBudget() {}

  static Duration remaining() {
    Context context = Context.current();
    Deadline deadline = context.getDeadline();
    if (deadline != null && deadline.isExpired()) {
      throw expired();
    }
    if (context.isCancelled()) {
      throw Status.CANCELLED.withDescription("the caller cancelled the call").asRuntimeException();
    }
    if (deadline == null) {
      return NO_DEADLINE;
    }
    long millis = deadline.timeRemaining(TimeUnit.MILLISECONDS);
    if (millis <= 0) {
      throw expired();
    }
    return Duration.ofMillis(millis);
  }

  /** True when the current call's deadline has passed. */
  static boolean deadlineExpired() {
    Deadline deadline = Context.current().getDeadline();
    return deadline != null && deadline.isExpired();
  }

  private static RuntimeException expired() {
    return Status.DEADLINE_EXCEEDED
        .withDescription("the deadline expired before the work started")
        .asRuntimeException();
  }
}
