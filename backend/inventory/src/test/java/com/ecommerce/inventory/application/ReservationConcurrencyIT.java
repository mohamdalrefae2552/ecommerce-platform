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
 * PROOF-R2-1 at the service boundary: many simultaneous ReserveStock calls over gRPC for the last
 * unit of a product; exactly one succeeds and the stock never goes below zero (docs/05 row 1).
 */
class ReservationConcurrencyIT extends IntegrationTest {

  private static final int CALLERS = 50;

  @RepeatedTest(3)
  void exactlyOneOfManySimultaneousReservationsGetsTheLastUnit() throws Exception {
    String product = productId(1);
    givenStock(product, 1);
    List<Callable<ReserveOutcome>> calls = new ArrayList<>();
    for (int i = 0; i < CALLERS; i++) {
      ReserveStockRequest request =
          ReserveStockRequest.newBuilder()
              .setReservationId(UUID.randomUUID().toString())
              .addItems(ReservationItem.newBuilder().setProductId(product).setQuantity(1))
              .build();
      calls.add(() -> inventory().reserveStock(request).getOutcome());
    }

    List<ReserveOutcome> outcomes = ConcurrentCalls.runSimultaneously(calls);

    assertThat(outcomes).filteredOn(ReserveOutcome.RESERVE_OUTCOME_RESERVED::equals).hasSize(1);
    assertThat(outcomes)
        .filteredOn(ReserveOutcome.RESERVE_OUTCOME_INSUFFICIENT_STOCK::equals)
        .hasSize(CALLERS - 1);
    assertThat(availableOf(product)).isZero();
    assertThat(count("reservations")).isEqualTo(1);
    assertThat(count("reservation_lines")).isEqualTo(1);
  }

  private int count(String table) {
    return jdbc.sql("SELECT count(*) FROM " + table).query(Integer.class).single();
  }
}
