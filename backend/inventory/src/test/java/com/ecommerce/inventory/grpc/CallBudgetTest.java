package com.ecommerce.inventory.grpc;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import io.grpc.Context;
import io.grpc.Status;
import io.grpc.StatusRuntimeException;
import java.time.Duration;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.Test;

class CallBudgetTest {

  private final ScheduledExecutorService scheduler = Executors.newSingleThreadScheduledExecutor();

  @AfterEach
  void stopScheduler() {
    scheduler.shutdownNow();
  }

  @Test
  void isTheTimeLeftBeforeTheCallerDeadline() throws Exception {
    Context.CancellableContext context =
        Context.current().withDeadlineAfter(800, TimeUnit.MILLISECONDS, scheduler);
    try {
      Duration remaining = context.call(CallBudget::remaining);

      assertThat(remaining).isPositive().isLessThanOrEqualTo(Duration.ofMillis(800));
    } finally {
      context.cancel(null);
    }
  }

  @Test
  void isUnboundedWhenTheCallerSetNoDeadline() {
    assertThat(CallBudget.remaining()).isEqualTo(CallBudget.NO_DEADLINE);
  }

  @Test
  void refusesToStartWorkOnceTheDeadlineHasPassed() {
    Context.CancellableContext context =
        Context.current().withDeadlineAfter(-1, TimeUnit.MILLISECONDS, scheduler);

    assertThatThrownBy(() -> context.call(CallBudget::remaining))
        .isInstanceOfSatisfying(
            StatusRuntimeException.class,
            e -> assertThat(e.getStatus().getCode()).isEqualTo(Status.Code.DEADLINE_EXCEEDED));
  }

  @Test
  void refusesToStartWorkWhenTheCallerCancelled() {
    Context.CancellableContext context = Context.current().withCancellation();
    context.cancel(null);

    assertThatThrownBy(() -> context.call(CallBudget::remaining))
        .isInstanceOfSatisfying(
            StatusRuntimeException.class,
            e -> assertThat(e.getStatus().getCode()).isEqualTo(Status.Code.CANCELLED));
  }
}
