package com.ecommerce.inventory.application;

import static org.assertj.core.api.Assertions.assertThat;

import com.ecommerce.inventory.ConcurrentCalls;
import com.ecommerce.inventory.IntegrationTest;
import com.ecommerce.inventory.domain.ReleaseReason;
import com.ecommerce.inventory.domain.ReleaseResult;
import com.ecommerce.inventory.domain.ReservationLine;
import com.ecommerce.inventory.domain.ReserveResult;
import java.util.Collections;
import java.util.List;
import java.util.UUID;
import java.util.concurrent.Callable;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;

/** Releasing returns the quantities exactly once, however often it is repeated (docs/05 row 5). */
class ReleaseIdempotencyIT extends IntegrationTest {

  @Autowired private ReservationService reservations;

  @Test
  void returnsTheQuantitiesOnceAndAnswersRepeatsAsAlreadyReleased() {
    givenStock(productId(1), 10);
    givenStock(productId(2), 4);
    UUID id = UUID.randomUUID();
    reservations.reserve(
        id,
        List.of(new ReservationLine(productId(2), 4), new ReservationLine(productId(1), 3)),
        BUDGET);

    assertThat(reservations.release(id, ReleaseReason.ORDER_CANCELLED, BUDGET))
        .isEqualTo(ReleaseResult.RELEASED);
    assertThat(reservations.release(id, ReleaseReason.ORDER_CANCELLED, BUDGET))
        .isEqualTo(ReleaseResult.ALREADY_RELEASED);

    assertThat(availableOf(productId(1))).isEqualTo(10);
    assertThat(availableOf(productId(2))).isEqualTo(4);
    assertThat(reservations.reserve(id, List.of(new ReservationLine(productId(1), 1)), BUDGET))
        .isEqualTo(new ReserveResult.AlreadyReleased());
  }

  @Test
  void simultaneousReleasesOfOneReservationReturnTheQuantitiesOnlyOnce() throws Exception {
    String product = productId(1);
    givenStock(product, 10);
    UUID id = UUID.randomUUID();
    reservations.reserve(id, List.of(new ReservationLine(product, 6)), BUDGET);
    Callable<ReleaseResult> release =
        () -> reservations.release(id, ReleaseReason.ORDER_PERSISTENCE_FAILED, BUDGET);

    List<ReleaseResult> results =
        ConcurrentCalls.runSimultaneously(Collections.nCopies(10, release));

    assertThat(results).filteredOn(ReleaseResult.RELEASED::equals).hasSize(1);
    assertThat(results).filteredOn(ReleaseResult.ALREADY_RELEASED::equals).hasSize(9);
    assertThat(availableOf(product)).isEqualTo(10);
  }
}
