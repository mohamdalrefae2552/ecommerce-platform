package com.ecommerce.inventory.application;

import static org.assertj.core.api.Assertions.assertThat;

import com.ecommerce.inventory.ConcurrentCalls;
import com.ecommerce.inventory.IntegrationTest;
import com.ecommerce.inventory.domain.ReservationLine;
import com.ecommerce.inventory.domain.ReserveResult;
import java.util.Collections;
import java.util.List;
import java.util.UUID;
import java.util.concurrent.Callable;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;

/** Repeating ReserveStock with the same reservation_id never reserves twice (docs/05 row 3). */
class ReserveIdempotencyIT extends IntegrationTest {

  @Autowired private ReservationService reservations;

  @Test
  void aRepeatedReservationIsAnsweredFromTheStoredOneWithoutReservingAgain() {
    String product = productId(1);
    givenStock(product, 10);
    UUID id = UUID.randomUUID();
    List<ReservationLine> lines = List.of(new ReservationLine(product, 3));

    assertThat(reservations.reserve(id, lines, BUDGET)).isEqualTo(new ReserveResult.Reserved());
    assertThat(reservations.reserve(id, lines, BUDGET)).isEqualTo(new ReserveResult.Reserved());

    assertThat(availableOf(product)).isEqualTo(7);
  }

  @Test
  void simultaneousRepeatsOfTheSameReservationReserveOnlyOnce() throws Exception {
    String product = productId(1);
    givenStock(product, 10);
    UUID id = UUID.randomUUID();
    List<ReservationLine> lines = List.of(new ReservationLine(product, 2));
    Callable<ReserveResult> reserve = () -> reservations.reserve(id, lines, BUDGET);

    List<ReserveResult> results =
        ConcurrentCalls.runSimultaneously(Collections.nCopies(10, reserve));

    assertThat(results).containsOnly(new ReserveResult.Reserved());
    assertThat(availableOf(product)).isEqualTo(8);
  }
}
