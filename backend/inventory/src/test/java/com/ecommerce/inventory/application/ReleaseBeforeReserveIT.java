package com.ecommerce.inventory.application;

import static org.assertj.core.api.Assertions.assertThat;

import com.ecommerce.inventory.IntegrationTest;
import com.ecommerce.inventory.domain.ReleaseReason;
import com.ecommerce.inventory.domain.ReleaseResult;
import com.ecommerce.inventory.domain.ReservationLine;
import com.ecommerce.inventory.domain.ReserveResult;
import java.util.List;
import java.util.UUID;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;

/**
 * The client timed out and compensated before its slow ReserveStock arrived: the release writes a
 * tombstone, so the late reservation reserves nothing (docs/05 row 4, proto rule 4).
 */
class ReleaseBeforeReserveIT extends IntegrationTest {

  @Autowired private ReservationService reservations;

  @Test
  void aReleaseOfAnUnknownIdTombstonesItAndBlocksTheLateReservation() {
    String product = productId(1);
    givenStock(product, 5);
    UUID id = UUID.randomUUID();

    assertThat(reservations.release(id, ReleaseReason.RESERVE_OUTCOME_UNKNOWN))
        .isEqualTo(ReleaseResult.NOT_FOUND_TOMBSTONED);
    assertThat(reservations.reserve(id, List.of(new ReservationLine(product, 2))))
        .isEqualTo(new ReserveResult.AlreadyReleased());

    assertThat(availableOf(product)).isEqualTo(5);
    assertThat(
            jdbc.sql("SELECT release_reason FROM reservations WHERE id = :id")
                .param("id", id)
                .query(String.class)
                .single())
        .isEqualTo("RESERVE_OUTCOME_UNKNOWN");
  }
}
