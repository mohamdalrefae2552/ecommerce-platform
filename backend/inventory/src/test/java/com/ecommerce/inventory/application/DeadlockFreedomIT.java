package com.ecommerce.inventory.application;

import static org.assertj.core.api.Assertions.assertThat;

import com.ecommerce.inventory.ConcurrentCalls;
import com.ecommerce.inventory.IntegrationTest;
import com.ecommerce.inventory.grpc.v1.ReservationItem;
import com.ecommerce.inventory.grpc.v1.ReserveOutcome;
import com.ecommerce.inventory.grpc.v1.ReserveStockRequest;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import java.util.concurrent.Callable;
import org.junit.jupiter.api.RepeatedTest;

/**
 * Orders listing the same products in opposite orders, (A,B) and (B,A), at the same time: rows are
 * always locked in product_id order, so none deadlocks and all succeed (docs/05 row 2).
 */
class DeadlockFreedomIT extends IntegrationTest {

  /**
   * As many concurrent callers as the main application can produce (PHP-FPM max children), each
   * doing real work on both rows. With more, late callers hit statement_timeout while queueing on
   * the two hot rows in this slow test environment, which is not what this test is about.
   */
  private static final int CALLERS = 16;

  @RepeatedTest(3)
  void opposingProductOrdersAllSucceedWithoutDeadlock() throws Exception {
    String a = productId(1);
    String b = productId(2);
    givenStock(a, 100);
    givenStock(b, 100);
    List<Callable<ReserveOutcome>> calls = new ArrayList<>();
    for (int i = 0; i < CALLERS; i++) {
      String first = i % 2 == 0 ? a : b;
      String second = i % 2 == 0 ? b : a;
      ReserveStockRequest request =
          ReserveStockRequest.newBuilder()
              .setReservationId(UUID.randomUUID().toString())
              .addItems(ReservationItem.newBuilder().setProductId(first).setQuantity(1))
              .addItems(ReservationItem.newBuilder().setProductId(second).setQuantity(1))
              .build();
      calls.add(() -> inventory().reserveStock(request).getOutcome());
    }

    List<ReserveOutcome> outcomes = ConcurrentCalls.runSimultaneously(calls);

    assertThat(outcomes).hasSize(CALLERS).containsOnly(ReserveOutcome.RESERVE_OUTCOME_RESERVED);
    assertThat(availableOf(a)).isEqualTo(100 - CALLERS);
    assertThat(availableOf(b)).isEqualTo(100 - CALLERS);
  }
}
